import { render, useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { PanelBody, TextControl, Button, Notice, ToggleControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip as RechartsTooltip, Legend } from 'recharts';
import { createClient } from '@supabase/supabase-js';
import { Activity, Globe, Zap, ShieldCheck } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import './index.css';

/* global gleoData */
const seoPluginActive = typeof gleoData !== 'undefined' ? gleoData.seoPluginActive : false;
const seoPluginName = typeof gleoData !== 'undefined' ? gleoData.seoPluginName : '';

// ---- Fix type config ----
const FIX_CONFIG = {
    schema:            { label: 'Apply Schema',        needsInput: false, successMsg: 'JSON-LD schema is now active on this post.' },
    capsule:           { label: 'Add AI Summary',       needsInput: false, successMsg: 'An AI-generated summary has been appended to the bottom of this post.' },
    structure:         { label: 'Add Headings',         needsInput: false, successMsg: 'H2 headings have been inserted into your post content.' },
    formatting:        { label: 'Add Lists',            needsInput: false, successMsg: 'A long paragraph has been converted into a bullet list.' },
    readability:       { label: 'Shorten Paragraphs',   needsInput: false, successMsg: 'Long paragraphs have been split into shorter, scannable chunks.' },
    content_depth:     { label: 'Expand Content',       needsInput: false, successMsg: 'Additional in-depth paragraphs have been added to your post.' },
    data_tables:       { label: 'Add Table',            needsInput: false, successMsg: 'A comparison table has been added to your post.' },
    answer_readiness:  { label: 'Add Q&A Block',        needsInput: false, successMsg: 'A Q&A pattern block has been inserted into your post.' },
    faq:               { label: 'Add FAQ',              needsInput: false, successMsg: 'FAQ section has been added to your post.' },
    authority:         { label: 'Add Statistics',       needsInput: false, successMsg: 'A statistics callout block has been inserted near the top of your post.' },
    credibility:       { label: 'Add Sources',          needsInput: true,  prompt: 'Paste URLs to authoritative sources (one per line):', inputType: 'lines', successMsg: 'A Sources & References section has been added to your post.' },
};

const AREA_TO_FIX = {
    'Schema Markup':    'schema',
    'Structure':        'structure',
    'Content Depth':    'content_depth',
    'Formatting':       'formatting',
    'Authority':        'authority',
    'Credibility':      'credibility',
    'FAQ Section':      'faq',
    'Data Tables':      'data_tables',
    'Readability':      'readability',
    'Answer Readiness': 'answer_readiness',
    'Visual Content':   null,
    'Brand Visibility': null,
    'Overall':          null,
};

// ---- Sub-components ----

const TabNav = ({ activeTab, onTabChange }) => (
    <div className="gleo-tabs">
        <button className={`gleo-tab ${activeTab === 'analysis' ? 'active' : ''}`} onClick={() => onTabChange('analysis')}>🔍 Analysis</button>
        <button className={`gleo-tab ${activeTab === 'analytics' ? 'active' : ''}`} onClick={() => onTabChange('analytics')}>📊 Analytics</button>
    </div>
);

const ScoreGauge = ({ score, label }) => {
    const color = score >= 70 ? '#22c55e' : score >= 40 ? '#f59e0b' : '#ef4444';
    return (
        <div className="gleo-score-gauge">
            <div className="gleo-score-circle" style={{ borderColor: color }}>
                <span className="gleo-score-number" style={{ color }}>{score}</span>
            </div>
            <span className="gleo-score-label">{label}</span>
        </div>
    );
};

const PriorityBadge = ({ priority }) => (
    <span className={`gleo-priority-badge badge-${priority}`}>{priority}</span>
);

const Signal = ({ label, value, good, fixed }) => (
    <div className={`gleo-signal ${good === true || fixed ? 'good' : good === false ? 'bad' : ''}`}>
        <span className="gleo-signal-label">{label}</span>
        <span className="gleo-signal-value">{value}{fixed && ' ✨'}</span>
    </div>
);

// ---- Success Toast ----
const SuccessToast = ({ message, onDismiss }) => {
    useEffect(() => {
        const timer = setTimeout(onDismiss, 5000);
        return () => clearTimeout(timer);
    }, []);
    return (
        <div className="gleo-toast">
            <span className="gleo-toast-icon">✅</span>
            <span>{message}</span>
        </div>
    );
};

// ---- User Input Modal ----
const InputModal = ({ title, prompt, inputType, onSubmit, onCancel }) => {
    const [value, setValue] = useState('');
    const handleSubmit = () => {
        if (!value.trim()) return;
        onSubmit(inputType === 'lines' ? value.split('\n').map(l => l.trim()).filter(l => l) : value.trim());
    };
    return (
        <div className="gleo-modal-backdrop" onClick={onCancel}>
            <div className="gleo-modal" onClick={e => e.stopPropagation()}>
                <h3>{title}</h3>
                <p className="gleo-modal-prompt">{prompt}</p>
                <textarea className="gleo-modal-input" rows={inputType === 'lines' ? 5 : 3} value={value} onChange={e => setValue(e.target.value)} placeholder={inputType === 'lines' ? 'One item per line…' : 'Type here…'} />
                <div className="gleo-modal-actions">
                    <button className="gleo-btn-secondary" onClick={onCancel}>Cancel</button>
                    <button className="gleo-btn-apply" onClick={handleSubmit} disabled={!value.trim()}>Apply Fix</button>
                </div>
            </div>
        </div>
    );
};

// ---- SVG Line Chart ----
const LineChart = ({ data }) => {
    if (!data || data.length === 0) {
        return <p className="gleo-no-data">No historical data yet. Run your first scan to start tracking!</p>;
    }
    const width = 700, height = 220;
    const padding = { top: 20, right: 30, bottom: 40, left: 40 };
    const chartW = width - padding.left - padding.right;
    const chartH = height - padding.top - padding.bottom;
    const maxRate = 10, maxScore = 100;
    const xStep = data.length > 1 ? chartW / (data.length - 1) : chartW / 2;

    const brandPoints = data.map((d, i) => ({ x: padding.left + i * xStep, y: padding.top + chartH - (d.avg_brand_rate / maxRate) * chartH }));
    const scorePoints = data.map((d, i) => ({ x: padding.left + i * xStep, y: padding.top + chartH - (d.avg_geo_score / maxScore) * chartH }));
    const toPath = (points) => points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');

    return (
        <div className="gleo-chart-wrap">
            <svg viewBox={`0 0 ${width} ${height}`} className="gleo-line-chart">
                {[0, 25, 50, 75, 100].map(v => {
                    const y = padding.top + chartH - (v / 100) * chartH;
                    return <line key={v} x1={padding.left} y1={y} x2={width - padding.right} y2={y} stroke="#e2e8f0" strokeWidth="1" />;
                })}
                <path d={toPath(brandPoints)} fill="none" stroke="#3b82f6" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                {brandPoints.map((p, i) => <circle key={`b${i}`} cx={p.x} cy={p.y} r="4" fill="#3b82f6" />)}
                <path d={toPath(scorePoints)} fill="none" stroke="#22c55e" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                {scorePoints.map((p, i) => <circle key={`s${i}`} cx={p.x} cy={p.y} r="4" fill="#22c55e" />)}
                {data.map((d, i) => (
                    <text key={i} x={padding.left + i * xStep} y={height - 8} textAnchor="middle" fontSize="10" fill="#64748b">
                        {d.scan_date ? d.scan_date.substring(5) : `#${i + 1}`}
                    </text>
                ))}
                {[0, 25, 50, 75, 100].map(v => (
                    <text key={v} x={padding.left - 8} y={padding.top + chartH - (v / 100) * chartH + 4} textAnchor="end" fontSize="10" fill="#64748b">{v}</text>
                ))}
            </svg>
            <div className="gleo-chart-legend">
                <span className="gleo-legend-item"><span className="gleo-legend-dot" style={{ background: '#3b82f6' }}></span> AI Visibility Score (×10)</span>
                <span className="gleo-legend-item"><span className="gleo-legend-dot" style={{ background: '#22c55e' }}></span> Avg GEO Score</span>
            </div>
        </div>
    );
};

// ---- Historical Chart for Specific Post ----
const PostHistoryChart = ({ postId }) => {
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        apiFetch({ path: `/gleo/v1/analytics/history?post_id=${postId}` })
            .then((res) => setHistory(res.history || []))
            .finally(() => setLoading(false));
    }, [postId]);

    return (
        <div className="gleo-section">
            <h4>📈 AI Visibility Over Time</h4>
            <p style={{ fontSize: 13, color: 'hsl(var(--muted-foreground))', marginBottom: 12 }}>
                Tracks how often this post appears in AI-generated answers across multiple scans.
            </p>
            {loading ? <p>Loading chart data…</p> : <LineChart data={history} />}
        </div>
    );
};

// ---- Analytics Tab (SOV + Realtime Bot Feed) ----
const SUPABASE_URL = 'https://biklzdwqywuxdcadsefn.supabase.co';
const SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJpa2x6ZHdxeXd1eGRjYWRzZWZuIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM0NDI0NzMsImV4cCI6MjA4OTAxODQ3M30.7rMLpUi827mi641__NBOB4LkaX1wROWQn11rIcSQm4M';

const supabase = createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

const AnalyticsTab = () => {
    const [sovData, setSovData] = useState(null);
    const [isRefreshingSov, setIsRefreshingSov] = useState(false);
    const [refreshMsg, setRefreshMsg] = useState(null);
    const [botFeed, setBotFeed] = useState([]);
    const siteId = useMemo(() => typeof gleoData !== 'undefined' ? new URL(gleoData.siteUrl).hostname : '', []);

    const node_api_url = 'http://localhost:3000';

    const handleRefreshSov = () => {
        setIsRefreshingSov(true);
        setRefreshMsg(null);
        fetch(`${node_api_url}/v1/analytics/sov/refresh`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_id: siteId })
        })
        .then(res => res.json())
        .then(() => {
            fetch(`${node_api_url}/v1/analytics/sov?site_id=${siteId}`)
                .then(r => r.json())
                .then(r => { setSovData(r.data); setRefreshMsg('Updated!'); })
                .catch(() => {});
        })
        .catch(err => setRefreshMsg('Error: ' + err.message))
        .finally(() => setIsRefreshingSov(false));
    };

    useEffect(() => {
        fetch(`${node_api_url}/v1/analytics/sov?site_id=${siteId}`)
            .then(res => res.json())
            .then(res => setSovData(res.data))
            .catch(() => {});

        fetch(`${node_api_url}/v1/analytics/bot-feed?site_id=${siteId}`)
            .then(res => res.json())
            .then(res => setBotFeed(res.data || []))
            .catch(() => {});

        const channel = supabase
            .channel('bot_hits')
            .on('postgres_changes',
                { event: 'INSERT', schema: 'public', table: 'bot_traffic_logs', filter: `site_id=eq.${siteId}` },
                (payload) => setBotFeed(prev => [payload.new, ...prev].slice(0, 20))
            )
            .subscribe();

        return () => supabase.removeChannel(channel);
    }, [siteId]);

    return (
        <div className="gleo-analytics-tab">
            <div className="gleo-analytics-grid">

                {/* 1. AI Visibility Share */}
                <div className="gleo-card gleo-sov-card">
                    <div className="gleo-card-header" style={{ justifyContent: 'space-between', display: 'flex' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                            <Activity size={18} />
                            <h3>Your AI Visibility</h3>
                        </div>
                        <button
                            className="gleo-btn-secondary gleo-btn-sm"
                            onClick={handleRefreshSov}
                            disabled={isRefreshingSov}
                            title="Run a fresh AI analysis to update your visibility score"
                        >
                            {isRefreshingSov ? 'Running...' : '↻ Refresh'}
                        </button>
                    </div>
                    <p className="gleo-card-desc">How often your site appears when AI like ChatGPT and Perplexity answers questions in your industry.</p>
                    {refreshMsg && <p style={{ fontSize: 12, color: '#10b981', margin: '4px 0 0', fontWeight: 600 }}>{refreshMsg}</p>}

                    <div style={{ marginTop: 20 }}>
                        {sovData ? (() => {
                            const shares = sovData.market_share || [];
                            const yourEntry = shares[0] || { name: sovData.brand_name || 'Your Site', percentage: 0 };
                            const rank = shares.findIndex(e => e === yourEntry) + 1;
                            return (
                                <div>
                                    <div style={{ textAlign: 'center', marginBottom: 24, padding: '16px', background: 'hsl(var(--muted)/0.3)', borderRadius: 12 }}>
                                        <div style={{ fontSize: 52, fontWeight: 800, color: '#3b82f6', lineHeight: 1 }}>{yourEntry.percentage}%</div>
                                        <div style={{ fontSize: 13, color: 'hsl(var(--muted-foreground))', marginTop: 6 }}>of AI answers mention your site</div>
                                        <div style={{ marginTop: 10 }}>
                                            {rank === 1
                                                ? <span style={{ fontSize: 12, background: '#dcfce7', color: '#166534', padding: '4px 12px', borderRadius: 20, fontWeight: 600 }}>🏆 #1 in your industry</span>
                                                : <span style={{ fontSize: 12, background: '#fef3c7', color: '#92400e', padding: '4px 12px', borderRadius: 20, fontWeight: 600 }}>#{rank} in your industry</span>
                                            }
                                        </div>
                                    </div>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {shares.map((entry, i) => (
                                            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <span style={{ fontSize: 11, width: 18, flexShrink: 0, color: 'hsl(var(--muted-foreground))', textAlign: 'right', fontWeight: 600 }}>#{i + 1}</span>
                                                <span style={{ fontSize: 13, width: 130, flexShrink: 0, fontWeight: i === 0 ? 700 : 400, color: i === 0 ? 'hsl(var(--foreground))' : 'hsl(var(--muted-foreground))', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={entry.name}>
                                                    {i === 0 ? '⭐ ' : ''}{i === 0 ? 'Your Site' : entry.name}
                                                </span>
                                                <div style={{ flex: 1, background: 'hsl(var(--muted)/0.4)', borderRadius: 4, height: 10, overflow: 'hidden' }}>
                                                    <div style={{ width: `${entry.percentage}%`, height: '100%', background: i === 0 ? '#3b82f6' : '#94a3b8', borderRadius: 4, transition: 'width 1.2s ease' }} />
                                                </div>
                                                <span style={{ fontSize: 13, fontWeight: 600, width: 34, textAlign: 'right', color: i === 0 ? '#3b82f6' : 'hsl(var(--muted-foreground))' }}>{entry.percentage}%</span>
                                            </div>
                                        ))}
                                    </div>
                                    <p style={{ fontSize: 11, color: 'hsl(var(--muted-foreground))', marginTop: 14, lineHeight: 1.5, borderTop: '1px solid hsl(var(--border))', paddingTop: 10 }}>
                                        Simulated from AI queries in your industry. Refresh to run a new analysis.
                                    </p>
                                </div>
                            );
                        })() : (
                            <div className="gleo-no-data-v2">
                                <Zap size={32} />
                                <p>No data yet. Click "↻ Refresh" to run your first AI visibility analysis — takes about 30 seconds.</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* 2. Real-time Bot Tracker */}
                <div className="gleo-card gleo-bot-tracker">
                    <div className="gleo-card-header">
                        <Globe size={18} />
                        <h3>Live AI Crawler Activity</h3>
                    </div>
                    <p className="gleo-card-desc">See when AI bots like ChatGPT and Perplexity visit your site in real time.</p>

                    <div className="gleo-bot-feed">
                        {botFeed.length > 0 ? botFeed.map((hit, i) => (
                            <div key={hit.id || i} className="gleo-bot-hit-item">
                                <div className="gleo-bot-icon-wrap">
                                    <ShieldCheck size={16} />
                                </div>
                                <div className="gleo-bot-details">
                                    <div className="gleo-bot-row">
                                        <strong>{hit.bot_name}</strong>
                                        <span className="gleo-bot-time">{formatDistanceToNow(new Date(hit.timestamp))} ago</span>
                                    </div>
                                    <div className="gleo-bot-path">Crawled: <code>{hit.request_path}</code></div>
                                </div>
                            </div>
                        )) : (
                            <div className="gleo-no-data-v2">
                                <Activity size={32} />
                                <p>No bot visits recorded yet. This updates in real-time when AI crawlers visit your site.</p>
                            </div>
                        )}
                    </div>
                </div>

            </div>
        </div>
    );
};

// ---- Collapsible Priority Section ----
const PrioritySection = ({ priority, items, onFix }) => {
    // Open critical, high priority, and medium (improvements) by default
    const [open, setOpen] = useState(priority === 'critical' || priority === 'high' || priority === 'medium');
    if (!items || items.length === 0) return null;
    const labels = { critical: 'Critical Issues', high: 'High Priority', medium: 'Improvements', positive: 'Positive Signals' };
    const icons = { critical: '🔴', high: '🟡', medium: '🔵', positive: '🟢' };

    return (
        <div className={`gleo-priority-section priority-${priority}`}>
            <div className="gleo-priority-header" onClick={() => setOpen(!open)}>
                <span className="gleo-priority-icon">{icons[priority] || '⚪'}</span>
                <span className="gleo-priority-title">{labels[priority] || priority}</span>
                <span className="gleo-priority-count">{items.length}</span>
                <span className="gleo-expand-icon">{open ? '▼' : '▶'}</span>
            </div>
            {open && (
                <div className="gleo-priority-items">
                    {items.map((item, i) => (
                        <div key={i} className="gleo-rec-card">
                            <div className="gleo-rec-card-body">
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '4px' }}>
                                    <strong>{item.area}</strong>
                                    {item.maxScore !== undefined && (
                                        <span style={{ fontSize: '12px', fontWeight: 'bold', color: item.score === item.maxScore ? '#10b981' : (item.score > 0 ? '#f59e0b' : '#ef4444'), background: 'rgba(0,0,0,0.05)', padding: '2px 8px', borderRadius: '12px' }}>
                                            {item.score} / {item.maxScore} pts
                                        </span>
                                    )}
                                </div>
                                <p>{item.message}</p>
                            </div>
                            <div className="gleo-rec-card-action">
                                {item.fixType ? (
                                    <button className="gleo-btn-apply gleo-btn-sm" onClick={() => onFix(item.fixType, item)} disabled={item.applied || item.applying}>
                                        {item.applied ? '✓ Applied' : (item.applying ? 'Fixing…' : 'Fix')}
                                    </button>
                                ) : (
                                    <span className="gleo-manual-tag">Info</span>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

// ---- Scan Complete / What's New Modal ----
const ScanCompleteModal = ({ resultCount, scanResults, onClose }) => {
    const topIssues = [];
    for (const report of (scanResults || [])) {
        if (!report.result) continue;
        for (const rec of (report.result.recommendations || [])) {
            if (topIssues.length >= 4) break;
            if (rec.priority === 'critical' || rec.priority === 'high') {
                topIssues.push({ ...rec, postTitle: report.result.title || `Post #${report.post_id}` });
            }
        }
        if (topIssues.length >= 4) break;
    }
    const totalFixes = (scanResults || []).reduce((sum, r) => sum + (r.result?.recommendations?.length || 0), 0);
    const autoFixable = (scanResults || []).reduce((sum, r) => {
        return sum + (r.result?.recommendations || []).filter(rec => AREA_TO_FIX[rec.area]).length;
    }, 0);

    return (
        <div className="gleo-modal-backdrop" onClick={onClose}>
            <div className="gleo-modal" onClick={e => e.stopPropagation()} style={{ maxWidth: 500 }}>
                <div style={{ textAlign: 'center', paddingBottom: 16, borderBottom: '1px solid hsl(var(--border))' }}>
                    <div style={{ fontSize: 40, marginBottom: 8 }}>✅</div>
                    <h3 style={{ fontSize: '1.2rem', marginBottom: 6 }}>Analysis Complete!</h3>
                    <p style={{ color: 'hsl(var(--muted-foreground))', fontSize: 14, lineHeight: 1.5, margin: 0 }}>
                        Found <strong>{totalFixes} ways to improve</strong> across {resultCount} post{resultCount !== 1 ? 's' : ''} —{' '}
                        <strong style={{ color: '#10b981' }}>{autoFixable} can be fixed automatically.</strong>
                    </p>
                </div>

                {topIssues.length > 0 && (
                    <div style={{ margin: '16px 0' }}>
                        <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: 'hsl(var(--muted-foreground))', marginBottom: 10 }}>
                            What We Found
                        </p>
                        {topIssues.map((issue, i) => (
                            <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '10px 0', borderBottom: i < topIssues.length - 1 ? '1px solid hsl(var(--border))' : 'none' }}>
                                <span style={{ fontSize: 11, background: issue.priority === 'critical' ? '#ef4444' : '#f59e0b', color: 'white', padding: '3px 7px', borderRadius: 4, flexShrink: 0, marginTop: 1, fontWeight: 700 }}>
                                    {issue.priority}
                                </span>
                                <div>
                                    <strong style={{ fontSize: 13, display: 'block', marginBottom: 2 }}>{issue.area}</strong>
                                    <span style={{ fontSize: 12, color: 'hsl(var(--muted-foreground))', lineHeight: 1.4 }}>{issue.message}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <button className="gleo-btn-apply" onClick={onClose} style={{ flex: 1, height: 44, fontSize: 14 }}>
                        View Results & Fix All →
                    </button>
                </div>
                <p style={{ fontSize: 11, color: 'hsl(var(--muted-foreground))', textAlign: 'center', marginTop: 10 }}>
                    Expand a post below to see its full report and preview changes live on your site.
                </p>
            </div>
        </div>
    );
};

// ---- Live Site Preview (split-panel with issues sidebar) ----
const SitePreview = ({ url, onClose, onApplyAll, applyingAll, allApplied, items, onFix }) => {
    const [iframeKey, setIframeKey] = useState(Date.now());
    const [iframeLoaded, setIframeLoaded] = useState(false);

    useEffect(() => {
        if (!applyingAll && allApplied) {
            setIframeKey(Date.now());
            setIframeLoaded(false);
        }
    }, [applyingAll, allApplied]);

    const issueItems = (items || []).filter(i => i.priority === 'critical' || i.priority === 'high');
    const otherItems = (items || []).filter(i => i.priority !== 'critical' && i.priority !== 'high');

    return (
        <div style={{ position: 'fixed', inset: 0, zIndex: 99999, background: '#0f172a', display: 'flex', flexDirection: 'column' }}>
            {/* Header */}
            <div style={{ padding: '12px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#0f172a', borderBottom: '1px solid #1e293b', flexShrink: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                    <h3 style={{ margin: 0, color: '#f8fafc', fontSize: 17, display: 'flex', alignItems: 'center', gap: 8 }}>
                        <Globe size={18} /> Live Preview
                    </h3>
                    {!allApplied ? (
                        <button className="gleo-btn-apply" onClick={onApplyAll} disabled={applyingAll} style={{ padding: '8px 20px', fontSize: 14 }}>
                            {applyingAll ? '⏳ Applying fixes...' : '⚡ Apply All Auto-Fixes'}
                        </button>
                    ) : (
                        <span style={{ color: '#10b981', fontWeight: 600, fontSize: 14 }}>✓ All auto-fixes applied!</span>
                    )}
                </div>
                <button onClick={onClose} style={{ background: '#1e293b', color: '#94a3b8', border: '1px solid #334155', padding: '8px 16px', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
                    ✕ Close Preview
                </button>
            </div>

            {/* Split layout: sidebar + iframe */}
            <div style={{ display: 'flex', flex: 1, overflow: 'hidden' }}>
                {/* Issues Sidebar */}
                <div style={{ width: 300, background: '#0f172a', borderRight: '1px solid #1e293b', overflowY: 'auto', flexShrink: 0, padding: '16px 14px' }}>
                    <p style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748b', marginBottom: 12 }}>
                        Issues to Fix
                    </p>

                    {issueItems.length === 0 && otherItems.length === 0 && (
                        <p style={{ color: '#64748b', fontSize: 13, lineHeight: 1.5 }}>No issues to display. Apply fixes to boost your GEO score.</p>
                    )}

                    {issueItems.map((item, i) => (
                        <div key={i} style={{ background: '#1e293b', borderRadius: 8, padding: '11px 12px', marginBottom: 8, border: `1px solid ${item.priority === 'critical' ? 'rgba(239,68,68,0.3)' : 'rgba(245,158,11,0.3)'}` }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 5 }}>
                                <strong style={{ color: '#f1f5f9', fontSize: 13 }}>{item.area}</strong>
                                <span style={{ fontSize: 10, background: item.priority === 'critical' ? '#ef4444' : '#f59e0b', color: 'white', padding: '2px 6px', borderRadius: 3, fontWeight: 700 }}>{item.priority}</span>
                            </div>
                            <p style={{ color: '#94a3b8', fontSize: 12, lineHeight: 1.5, margin: '0 0 8px' }}>{item.message}</p>
                            {item.fixType && !item.applied && (
                                <button onClick={() => onFix(item.fixType)} style={{ background: '#3b82f6', color: 'white', border: 'none', padding: '5px 12px', borderRadius: 4, fontSize: 12, cursor: 'pointer', fontWeight: 600 }}>
                                    Fix This →
                                </button>
                            )}
                            {item.applied && <span style={{ color: '#10b981', fontSize: 12, fontWeight: 600 }}>✓ Fixed</span>}
                        </div>
                    ))}

                    {otherItems.length > 0 && (
                        <>
                            <p style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748b', marginBottom: 10, marginTop: 16 }}>
                                Improvements
                            </p>
                            {otherItems.map((item, i) => (
                                <div key={i} style={{ background: '#1e293b', borderRadius: 8, padding: '10px 12px', marginBottom: 6, border: '1px solid #334155' }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <strong style={{ color: '#cbd5e1', fontSize: 12 }}>{item.area}</strong>
                                        {item.fixType && !item.applied && (
                                            <button onClick={() => onFix(item.fixType)} style={{ background: 'transparent', color: '#64748b', border: '1px solid #334155', padding: '3px 8px', borderRadius: 4, fontSize: 11, cursor: 'pointer' }}>
                                                Fix
                                            </button>
                                        )}
                                        {item.applied && <span style={{ color: '#10b981', fontSize: 11, fontWeight: 600 }}>✓</span>}
                                    </div>
                                </div>
                            ))}
                        </>
                    )}
                </div>

                {/* Preview iframe */}
                <div style={{ flex: 1, position: 'relative', background: '#fff' }}>
                    {(applyingAll || !iframeLoaded) && (
                        <div style={{ position: 'absolute', inset: 0, zIndex: 10, background: 'rgba(15,23,42,0.9)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
                            <div className="gleo-spinner" style={{ marginBottom: 16, width: 36, height: 36, borderTopColor: '#10b981', borderRightColor: '#10b981' }}></div>
                            <p style={{ color: '#f1f5f9', fontWeight: 600, margin: 0 }}>{applyingAll ? 'Applying your fixes...' : 'Loading preview...'}</p>
                            <p style={{ color: '#64748b', fontSize: 13, marginTop: 6 }}>This may take a few seconds.</p>
                        </div>
                    )}
                    <iframe
                        key={iframeKey}
                        src={`${url}&nocache=${iframeKey}`}
                        onLoad={() => setIframeLoaded(true)}
                        style={{ width: '100%', height: '100%', border: 'none', position: 'absolute', inset: 0 }}
                        title="Site Preview"
                    />
                </div>
            </div>
        </div>
    );
};

// ---- Report Card ----
const GeoReportCard = ({ report }) => {
    const { post_id, result } = report;
    const [expanded, setExpanded] = useState(false);
    const [appliedTypes, setAppliedTypes] = useState({});
    const [applyingTypes, setApplyingTypes] = useState({});
    const [toasts, setToasts] = useState([]);
    const [modal, setModal] = useState(null);
    const [showPreview, setShowPreview] = useState(false);
    const [isApplyingAll, setIsApplyingAll] = useState(false);
    const [showSchema, setShowSchema] = useState(false);
    const hasAutoOpenedPreview = useRef(false);

    // Build post URL before any early returns so hooks order is consistent
    const siteUrl = typeof gleoData !== 'undefined' ? gleoData.siteUrl : '';
    const postUrl = siteUrl ? `${siteUrl}/?p=${post_id}` : '';

    // Auto-open preview the first time the card is expanded
    useEffect(() => {
        if (expanded && !hasAutoOpenedPreview.current && postUrl) {
            hasAutoOpenedPreview.current = true;
            setShowPreview(true);
        }
    }, [expanded, postUrl]);

    if (!result) return null;

    const addToast = (message) => {
        const id = Date.now();
        setToasts(prev => [...prev, { id, message }]);
    };
    const removeToast = (id) => setToasts(prev => prev.filter(t => t.id !== id));

    const buildItems = () => {
        const items = [];
        if (result.json_ld_schema && !result.content_signals?.has_schema) {
            items.push({ priority: 'critical', area: 'Schema Markup', message: 'Inject AI-generated JSON-LD structured data.', fixType: 'schema', score: 0, maxScore: 10 });
        }
        if (result.recommendations) {
            for (const rec of result.recommendations) {
                items.push({ ...rec, fixType: AREA_TO_FIX[rec.area] || null });
            }
        }
        return items.map(item => ({
            ...item,
            applied: item.fixType ? !!appliedTypes[item.fixType] : false,
            applying: item.fixType ? !!applyingTypes[item.fixType] : false,
        }));
    };

    const allItems = buildItems();
    const grouped = {};
    for (const item of allItems) {
        const p = item.priority || 'medium';
        if (!grouped[p]) grouped[p] = [];
        grouped[p].push(item);
    }

    const doApply = (fixType, userInput) => {
        const config = FIX_CONFIG[fixType];
        setApplyingTypes(prev => ({ ...prev, [fixType]: true }));
        const data = { post_id, type: fixType, enabled: true };
        if (userInput !== undefined) data.user_input = userInput;
        apiFetch({ path: '/gleo/v1/apply', method: 'POST', data })
            .then(() => {
                setAppliedTypes(prev => ({ ...prev, [fixType]: true }));
                addToast(config?.successMsg || `${fixType} has been applied successfully.`);
            })
            .catch(err => addToast(`Failed to apply ${fixType}: ${err.message || 'Unknown error'}`))
            .finally(() => setApplyingTypes(prev => ({ ...prev, [fixType]: false })));
    };

    const handleFix = (fixType) => {
        const config = FIX_CONFIG[fixType];
        if (!config) return;
        if (config.needsInput) {
            setModal({ fixType, title: config.label, prompt: config.prompt, inputType: config.inputType });
        } else {
            doApply(fixType);
        }
    };

    const handleModalSubmit = (userInput) => {
        if (modal) { doApply(modal.fixType, userInput); setModal(null); }
    };

    const handleApplyAll = () => {
        setIsApplyingAll(true);
        const promises = [];
        const appliedNames = [];
        
        for (const item of allItems) {
            if (item.fixType && !item.applied && !FIX_CONFIG[item.fixType]?.needsInput) {
                const config = FIX_CONFIG[item.fixType];
                appliedNames.push(config.label || item.area);
                setApplyingTypes(prev => ({ ...prev, [item.fixType]: true }));
                const p = apiFetch({ path: '/gleo/v1/apply', method: 'POST', data: { post_id, type: item.fixType, enabled: true } })
                    .then(() => {
                        setAppliedTypes(prev => ({ ...prev, [item.fixType]: true }));
                    })
                    .finally(() => setApplyingTypes(prev => ({ ...prev, [item.fixType]: false })));
                promises.push(p);
            }
        }
        
        if (promises.length > 0) {
            Promise.all(promises).then(() => {
                const namesList = appliedNames.join(', ');
                addToast(`✨ Successfully applied: ${namesList}`);
            }).catch(() => {
                addToast("Some changes failed to apply. Please try again.");
            }).finally(() => {
                setIsApplyingAll(false);
            });
        } else {
            setIsApplyingAll(false);
        }
    };

    const allAutoFixed = allItems.filter(i => i.fixType && !FIX_CONFIG[i.fixType]?.needsInput).every(i => i.applied);

    return (
        <div className="gleo-report-card">
            {/* Toasts */}
            {toasts.length > 0 && (
                <div className="gleo-toast-container">
                    {toasts.map(t => <SuccessToast key={t.id} message={t.message} onDismiss={() => removeToast(t.id)} />)}
                </div>
            )}

            <div className="gleo-report-header" onClick={() => setExpanded(!expanded)}>
                <div className="gleo-report-title-row">
                    <h3>{result.title || `Post #${post_id}`}</h3>
                    <span className="gleo-expand-icon">{expanded ? '▼' : '▶'}</span>
                </div>
                <div className="gleo-report-scores">
                    <ScoreGauge score={result.geo_score} label="GEO Score" />
                    <ScoreGauge score={result.brand_inclusion_rate} label="AI Visibility /10" />
                </div>
            </div>

            {expanded && (
                <div className="gleo-report-body">

                    {/* LLMs.txt JSON-LD Visibility Badge */}
                    {(result.json_ld_schema && appliedTypes['schema']) && (
                        <div className="gleo-section" style={{ background: '#f8fafc', padding: '20px', borderRadius: '12px', border: '2px solid #10b981', marginBottom: '20px', boxShadow: '0 4px 6px -1px rgba(16, 185, 129, 0.1)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', color: '#047857', fontWeight: 700, fontSize: '16px' }}>
                                    <Zap size={20} /> JSON-LD Schema Successfully Applied to LLMs.txt!
                                </div>
                                <button 
                                    className="gleo-btn-secondary" 
                                    onClick={() => setShowSchema(!showSchema)} 
                                    style={{ padding: '8px 16px', fontSize: '14px', fontWeight: 'bold', background: showSchema ? '#e2e8f0' : '#fff', color: '#0f172a', border: '2px solid #cbd5e1' }}
                                >
                                    {showSchema ? 'Hide Code' : '👁️ View Schema Payload'}
                                </button>
                            </div>
                            <p style={{ fontSize: '14px', color: '#475569', marginBottom: showSchema ? '16px' : '0', lineHeight: 1.5 }}>
                                AI bots (like ChatGPT and Perplexity) can now read optimized semantic data directly from your site's <code>/llms.txt</code> file to confidently generate better answers about this content.
                            </p>
                            {showSchema && (
                                <pre style={{ background: '#1e293b', color: '#34d399', padding: '16px', borderRadius: '8px', fontSize: '13px', overflowX: 'auto', margin: 0, boxShadow: 'inset 0 2px 4px 0 rgba(0, 0, 0, 0.2)' }}>
                                    <code>{JSON.stringify(result.json_ld_schema, null, 2)}</code>
                                </pre>
                            )}
                        </div>
                    )}

                    {/* Content Signals — TOP */}
                    {result.content_signals && (
                        <div className="gleo-section">
                            <h4>📊 Content Signals</h4>
                            <div className="gleo-signals-grid">
                                <Signal label="Word Count" value={result.content_signals.word_count} />
                                <Signal label="Headings" value={result.content_signals.heading_count} good={result.content_signals.has_headings} fixed={!!appliedTypes['structure']} />
                                <Signal label="Lists" value={result.content_signals.list_item_count} good={result.content_signals.has_lists} fixed={!!appliedTypes['formatting']} />
                                <Signal label="Images" value={result.content_signals.image_count} good={result.content_signals.has_images} />
                                <Signal label="Citations" value={result.content_signals.citation_count} good={result.content_signals.has_citations} fixed={!!appliedTypes['credibility']} />
                                <Signal label="FAQ" value={result.content_signals.has_faq || appliedTypes['faq'] ? 'Yes' : 'No'} good={result.content_signals.has_faq} fixed={!!appliedTypes['faq']} />
                                <Signal label="Statistics" value={result.content_signals.has_statistics || appliedTypes['authority'] ? 'Yes' : 'No'} good={result.content_signals.has_statistics} fixed={!!appliedTypes['authority']} />
                                <Signal label="Schema" value={result.content_signals.has_schema || appliedTypes['schema'] ? 'Yes' : 'No'} good={result.content_signals.has_schema} fixed={!!appliedTypes['schema']} />
                            </div>
                        </div>
                    )}

                    {/* Brand History Chart Scoped to this Post */}
                    <PostHistoryChart postId={post_id} />

                    {/* Apply All + Preview */}
                    <div className="gleo-apply-all-row">
                        <button className="gleo-btn-apply" onClick={handleApplyAll} disabled={allAutoFixed}>
                            {allAutoFixed ? '✓ All Auto-Fixes Applied' : '✨ Apply All Auto-Fixes'}
                        </button>
                        {postUrl && (
                            <button className="gleo-btn-secondary" onClick={() => setShowPreview(!showPreview)}>
                                {showPreview ? '✕ Hide Preview' : '🌐 Preview on Site'}
                            </button>
                        )}
                        <span className="gleo-apply-hint">Fixes that need your input will still require a click.</span>
                    </div>

                    {/* Live Site Preview Modal */}
                    {showPreview && postUrl && (
                        <SitePreview
                            url={postUrl}
                            onClose={() => setShowPreview(false)}
                            onApplyAll={handleApplyAll}
                            applyingAll={isApplyingAll}
                            allApplied={allAutoFixed}
                            items={allItems}
                            onFix={handleFix}
                        />
                    )}

                    {/* Collapsible Priority Sections */}
                    <div className="gleo-section">
                        <h4>📋 Recommendations</h4>
                        {['critical', 'high', 'medium', 'positive'].map(p => (
                            <PrioritySection key={p} priority={p} items={grouped[p]} onFix={handleFix} />
                        ))}
                    </div>
                </div>
            )}

            {modal && (
                <InputModal title={modal.title} prompt={modal.prompt} inputType={modal.inputType} onSubmit={handleModalSubmit} onCancel={() => setModal(null)} />
            )}
        </div>
    );
};

// ---- Main App ----
const App = () => {
    const [activeTab, setActiveTab] = useState('analysis');
    const [clientId, setClientId] = useState('');
    const [secretKey, setSecretKey] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [saveStatus, setSaveStatus] = useState(null);
    const [isScanning, setIsScanning] = useState(false);
    const [scanProgress, setScanProgress] = useState(0);
    const [fakeProgress, setFakeProgress] = useState(0);
    const [scanResults, setScanResults] = useState([]);
    const [overrideSchema, setOverrideSchema] = useState(false);
    const [availablePosts, setAvailablePosts] = useState([]);
    const [selectedPosts, setSelectedPosts] = useState([]);
    const [isLoadingPosts, setIsLoadingPosts] = useState(true);
    const [showScanCompleteModal, setShowScanCompleteModal] = useState(false);
    const scanJustStarted = useRef(false);
    const progressIntervalRef = useRef(null);

    // Simulated progress: creeps from 8% → ~88% while waiting for real progress
    useEffect(() => {
        if (isScanning) {
            setFakeProgress(8);
            progressIntervalRef.current = setInterval(() => {
                setFakeProgress(prev => {
                    if (prev >= 88) return prev;
                    return prev + Math.max(0.4, (88 - prev) * 0.04);
                });
            }, 700);
        } else {
            clearInterval(progressIntervalRef.current);
            setFakeProgress(0);
        }
        return () => clearInterval(progressIntervalRef.current);
    }, [isScanning]);

    useEffect(() => {
        apiFetch({ path: '/wp/v2/settings' }).then((settings) => {
            setClientId(settings.gleo_client_id || '');
            setSecretKey(settings.gleo_secret_key || '');
            setOverrideSchema(settings.gleo_override_schema || false);
        });
        apiFetch({ path: '/wp/v2/posts?per_page=20&status=publish' })
            .then((posts) => { setAvailablePosts(posts); setSelectedPosts(posts.slice(0, 5).map(p => p.id)); setIsLoadingPosts(false); })
            .catch(() => setIsLoadingPosts(false));
        checkScanStatus();
    }, []);

    const checkScanStatus = () => {
        apiFetch({ path: '/gleo/v1/scan/status' })
            .then((res) => {
                setIsScanning(res.is_scanning); setScanProgress(res.progress);
                if (res.results && res.results.length > 0) {
                    setScanResults(res.results);
                    if (!res.is_scanning && scanJustStarted.current) {
                        setShowScanCompleteModal(true);
                        scanJustStarted.current = false;
                    }
                }
                if (res.is_scanning) setTimeout(checkScanStatus, 3000);
            }).catch(() => {});
    };

    const handleSave = () => {
        setIsSaving(true); setSaveStatus(null);
        apiFetch({ path: '/wp/v2/settings', method: 'POST', data: { gleo_client_id: clientId, gleo_secret_key: secretKey } })
            .then(() => setSaveStatus({ type: 'success', message: 'Settings saved successfully.' }))
            .catch((error) => setSaveStatus({ type: 'error', message: error.message || 'Error saving settings.' }))
            .finally(() => setIsSaving(false));
    };

    const togglePostSelection = (postId) => {
        setSelectedPosts(prev => prev.includes(postId) ? prev.filter(id => id !== postId) : [...prev, postId]);
    };

    const handleScan = () => {
        if (selectedPosts.length === 0) { setSaveStatus({ type: 'error', message: 'Please select at least one post to analyze.' }); return; }
        scanJustStarted.current = true;
        setIsScanning(true); setScanProgress(0); setScanResults([]); setSaveStatus(null);
        apiFetch({ path: '/gleo/v1/scan/start', method: 'POST', data: { post_ids: selectedPosts } })
            .then((res) => { setSaveStatus({ type: 'success', message: res.message }); checkScanStatus(); })
            .catch((err) => { setSaveStatus({ type: 'error', message: err.message || 'Error starting scan.' }); setIsScanning(false); });
    };

    return (
        <div className="gleo-dashboard">
            <h1 className="gleo-title">Gleo — Generative Engine Optimization</h1>
            <TabNav activeTab={activeTab} onTabChange={setActiveTab} />
            {saveStatus && <Notice status={saveStatus.type} isDismissible={false}>{saveStatus.message}</Notice>}

            {activeTab === 'analysis' && (
                <>
                    <PanelBody title="⚙️ API Credentials" initialOpen={false}>
                        <TextControl label="Client ID" value={clientId} onChange={setClientId} />
                        <TextControl label="Secret Key" value={secretKey} onChange={setSecretKey} type="password" />
                        <Button isPrimary onClick={handleSave} isBusy={isSaving}>Save Settings</Button>
                    </PanelBody>

                    {seoPluginActive && (
                        <div className="gleo-seo-warning">
                            <Notice status="warning" isDismissible={false}>
                                <strong>⚠️ {seoPluginName} Detected!</strong> Override schema globally or per-post in the reports.
                            </Notice>
                            <ToggleControl label="Global Schema Override" checked={overrideSchema} onChange={(val) => { setOverrideSchema(val); apiFetch({ path: '/wp/v2/settings', method: 'POST', data: { gleo_override_schema: val } }); }} help="Replace the existing SEO plugin schema with Gleo's AI-optimized schema." />
                        </div>
                    )}

                    <div className="gleo-card">
                        <h3>🔍 Site Selection & Analysis</h3>
                        <p style={{ color: 'hsl(var(--muted-foreground))', marginBottom: 16 }}>Select the posts you want to analyze for Generative Engine Optimization.</p>
                        {isLoadingPosts ? <p>Loading posts…</p> : (
                            <div className="gleo-post-list">
                                {availablePosts.map(post => (
                                    <div key={post.id} className="gleo-post-item" onClick={() => togglePostSelection(post.id)}>
                                        <input type="checkbox" checked={selectedPosts.includes(post.id)} onChange={() => {}} />
                                        <label>{post.title.rendered || `Post #${post.id}`}</label>
                                    </div>
                                ))}
                                {availablePosts.length === 0 && <p style={{ padding: 8 }}>No published posts found.</p>}
                            </div>
                        )}
                        <button className="gleo-btn-apply" style={{ width: '100%', height: 40, fontSize: 15 }} onClick={handleScan} disabled={isScanning || selectedPosts.length === 0}>
                            {isScanning ? 'Analyzing your posts…' : `🚀 Analyze Selected Posts (${selectedPosts.length})`}
                        </button>
                        {isScanning && (
                            <div style={{ marginTop: 16 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                                    <p style={{ fontSize: 13, color: 'hsl(var(--muted-foreground))', margin: 0 }}>Analyzing your content with AI…</p>
                                    <span style={{ fontSize: 12, fontWeight: 600, color: 'hsl(var(--muted-foreground))' }}>
                                        {Math.round(scanProgress > 0 ? scanProgress : fakeProgress)}%
                                    </span>
                                </div>
                                <div className="gleo-progress-bar">
                                    <div
                                        className="gleo-progress-fill"
                                        style={{ width: `${Math.min(scanProgress > 0 ? scanProgress : fakeProgress, 100)}%`, transition: 'width 0.7s ease' }}
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    {showScanCompleteModal && (
                        <ScanCompleteModal resultCount={scanResults.length} scanResults={scanResults} onClose={() => setShowScanCompleteModal(false)} />
                    )}

                    {!isScanning && scanResults.length > 0 && (
                        <div style={{ marginTop: 24 }}>
                            <h2 style={{ fontSize: '1.25rem', fontWeight: 600, marginBottom: 16 }}>📈 GEO Reports</h2>
                            {scanResults.map((report) => <GeoReportCard key={report.post_id} report={report} />)}
                        </div>
                    )}
                </>
            )}

            {activeTab === 'analytics' && <AnalyticsTab />}
        </div>
    );
};

document.addEventListener('DOMContentLoaded', () => {
    const rootElement = document.getElementById('gleo-admin-app');
    if (rootElement) { render(<App />, rootElement); }
});
