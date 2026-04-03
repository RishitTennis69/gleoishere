import { render, useState, useEffect, useMemo, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { createClient } from '@supabase/supabase-js';
import { Activity, Globe, Zap, ShieldCheck } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import './index.css';

/* global gleoData */
const seoPluginActive = typeof gleoData !== 'undefined' ? gleoData.seoPluginActive : false;
const seoPluginName  = typeof gleoData !== 'undefined' ? gleoData.seoPluginName  : '';

// ── Fix config ──────────────────────────────────────────────────────────────
const FIX_CONFIG = {
    schema:           { label: 'Apply Schema',       needsInput: false, successMsg: 'JSON-LD schema is now active on this post.' },
    capsule:          { label: 'Add AI Summary',      needsInput: false, successMsg: 'An AI-generated summary has been appended to the bottom of this post.' },
    structure:        { label: 'Add Headings',        needsInput: false, successMsg: 'H2 headings have been inserted into your post content.' },
    formatting:       { label: 'Add Lists',           needsInput: false, successMsg: 'A long paragraph has been converted into a bullet list.' },
    readability:      { label: 'Shorten Paragraphs',  needsInput: false, successMsg: 'Long paragraphs have been split into shorter, scannable chunks.' },
    content_depth:    { label: 'Expand Content',      needsInput: false, successMsg: 'Additional in-depth paragraphs have been added to your post.' },
    data_tables:      { label: 'Add Table',           needsInput: false, successMsg: 'A comparison table has been added to your post.' },
    answer_readiness: { label: 'Add Q&A Block',       needsInput: false, successMsg: 'A Q&A block has been inserted into your post.' },
    faq:              { label: 'Add FAQ',             needsInput: false, successMsg: 'FAQ section has been added to your post.' },
    authority:        { label: 'Add Statistics',      needsInput: false, successMsg: 'A statistics callout block has been inserted near the top of your post.' },
    credibility:      { label: 'Add Sources',         needsInput: true,  prompt: 'Paste URLs to authoritative sources (one per line):', inputType: 'lines', successMsg: 'A Sources & References section has been added to your post.' },
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

// ── Helpers ─────────────────────────────────────────────────────────────────
const scoreChipClass = s => s >= 70 ? 'chip-hi' : s >= 40 ? 'chip-md' : 'chip-lo';
const scoreBarColor  = s => s >= 70 ? '#22c55e' : s >= 40 ? '#f59e0b' : '#ef4444';

// ── SVG icons ────────────────────────────────────────────────────────────────
const IconDashboard = () => (
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" strokeWidth="1.5">
        <rect x="1" y="1" width="5.5" height="5.5" rx="1.2"/>
        <rect x="8.5" y="1" width="5.5" height="5.5" rx="1.2"/>
        <rect x="1" y="8.5" width="5.5" height="5.5" rx="1.2"/>
        <rect x="8.5" y="8.5" width="5.5" height="5.5" rx="1.2"/>
    </svg>
);
const IconAnalytics = () => (
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" strokeWidth="1.5">
        <polyline points="1,11 4.5,7 7.5,9 11,4 14,6"/>
    </svg>
);
const IconScan = () => (
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" strokeWidth="1.5">
        <circle cx="6.5" cy="6.5" r="4"/>
        <path d="M11 11l2.5 2.5"/>
    </svg>
);
const IconSettings = () => (
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" strokeWidth="1.5">
        <circle cx="7.5" cy="5" r="3"/>
        <path d="M2.5 13.5c0-2.8 2.2-5 5-5s5 2.2 5 5"/>
    </svg>
);
const IconChevron = ({ open }) => (
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.6"
        style={{ transform: open ? 'rotate(90deg)' : 'rotate(0deg)', transition: 'transform 0.15s', flexShrink: 0 }}>
        <path d="M4.5 3L7.5 6L4.5 9"/>
    </svg>
);

// ── Toast ────────────────────────────────────────────────────────────────────
const SuccessToast = ({ message, onDismiss }) => {
    useEffect(() => { const t = setTimeout(onDismiss, 5000); return () => clearTimeout(t); }, []);
    return (
        <div className="gleo-toast">
            <span className="gleo-toast-icon">&#10003;</span>
            <span>{message}</span>
        </div>
    );
};

// ── Input Modal ──────────────────────────────────────────────────────────────
const InputModal = ({ title, prompt, inputType, onSubmit, onCancel }) => {
    const [value, setValue] = useState('');
    const submit = () => {
        if (!value.trim()) return;
        onSubmit(inputType === 'lines' ? value.split('\n').map(l => l.trim()).filter(Boolean) : value.trim());
    };
    return (
        <div className="gleo-modal-backdrop" onClick={onCancel}>
            <div className="gleo-modal" onClick={e => e.stopPropagation()}>
                <h3>{title}</h3>
                <p className="gleo-modal-prompt">{prompt}</p>
                <textarea className="gleo-modal-input" rows={inputType === 'lines' ? 5 : 3}
                    value={value} onChange={e => setValue(e.target.value)}
                    placeholder={inputType === 'lines' ? 'One item per line…' : 'Type here…'} />
                <div className="gleo-modal-actions">
                    <button className="gleo-btn gleo-btn-outline" onClick={onCancel}>Cancel</button>
                    <button className="gleo-btn gleo-btn-primary" onClick={submit} disabled={!value.trim()}>Apply Fix</button>
                </div>
            </div>
        </div>
    );
};

// ── SVG Line Chart ───────────────────────────────────────────────────────────
const LineChart = ({ data }) => {
    if (!data || data.length === 0) {
        return <p className="gleo-no-data">No historical data yet. Run your first scan to start tracking.</p>;
    }
    const W = 680, H = 210;
    const pad = { top: 18, right: 24, bottom: 36, left: 36 };
    const cW = W - pad.left - pad.right;
    const cH = H - pad.top  - pad.bottom;
    const xStep = data.length > 1 ? cW / (data.length - 1) : cW / 2;
    const brandPts = data.map((d, i) => ({ x: pad.left + i * xStep, y: pad.top + cH - (d.avg_brand_rate / 10) * cH }));
    const scorePts = data.map((d, i) => ({ x: pad.left + i * xStep, y: pad.top + cH - (d.avg_geo_score / 100) * cH }));
    const path = pts => pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    return (
        <div className="gleo-chart-wrap">
            <svg viewBox={`0 0 ${W} ${H}`} className="gleo-line-chart">
                {[0, 25, 50, 75, 100].map(v => {
                    const y = pad.top + cH - (v / 100) * cH;
                    return <line key={v} x1={pad.left} y1={y} x2={W - pad.right} y2={y} stroke="#e2e8f0" strokeWidth="1"/>;
                })}
                <path d={path(brandPts)} fill="none" stroke="#3b82f6" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                {brandPts.map((p, i) => <circle key={i} cx={p.x} cy={p.y} r="3.5" fill="#3b82f6"/>)}
                <path d={path(scorePts)} fill="none" stroke="#22c55e" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                {scorePts.map((p, i) => <circle key={i} cx={p.x} cy={p.y} r="3.5" fill="#22c55e"/>)}
                {data.map((d, i) => (
                    <text key={i} x={pad.left + i * xStep} y={H - 8} textAnchor="middle" fontSize="10" fill="#9ca3af">
                        {d.scan_date ? d.scan_date.substring(5) : `#${i + 1}`}
                    </text>
                ))}
                {[0, 50, 100].map(v => (
                    <text key={v} x={pad.left - 6} y={pad.top + cH - (v / 100) * cH + 4}
                        textAnchor="end" fontSize="10" fill="#9ca3af">{v}</text>
                ))}
            </svg>
            <div className="gleo-chart-legend">
                <span className="gleo-legend-item"><span className="gleo-legend-dot" style={{ background: '#3b82f6' }}></span>AI Visibility (×10)</span>
                <span className="gleo-legend-item"><span className="gleo-legend-dot" style={{ background: '#22c55e' }}></span>GEO Score</span>
            </div>
        </div>
    );
};

const PostHistoryChart = ({ postId }) => {
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    useEffect(() => {
        setLoading(true);
        apiFetch({ path: `/gleo/v1/analytics/history?post_id=${postId}` })
            .then(res => setHistory(res.history || []))
            .finally(() => setLoading(false));
    }, [postId]);
    return (
        <div className="gleo-section">
            <h4>AI Visibility Over Time</h4>
            <p style={{ fontSize: 12.5, color: 'var(--fg-muted)', marginBottom: 10, marginTop: -4 }}>
                Tracks how often this post appears in AI-generated answers across scans.
            </p>
            {loading ? <p style={{ fontSize: 13, color: 'var(--fg-muted)' }}>Loading…</p> : <LineChart data={history}/>}
        </div>
    );
};

// ── Signal chip ──────────────────────────────────────────────────────────────
const Signal = ({ label, value, good, fixed }) => (
    <div className={`gleo-signal ${good === true || fixed ? 'good' : good === false ? 'bad' : ''}`}>
        <span className="gleo-signal-label">{label}</span>
        <span className="gleo-signal-value">{value}{fixed ? ' ✓' : ''}</span>
    </div>
);

// ── Analytics Tab ────────────────────────────────────────────────────────────
const SUPABASE_URL      = 'https://biklzdwqywuxdcadsefn.supabase.co';
const SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJpa2x6ZHdxeXd1eGRjYWRzZWZuIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM0NDI0NzMsImV4cCI6MjA4OTAxODQ3M30.7rMLpUi827mi641__NBOB4LkaX1wROWQn11rIcSQm4M';
const supabase          = createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

const AnalyticsTab = () => {
    const [sovData, setSovData]           = useState(null);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [refreshMsg, setRefreshMsg]     = useState(null);
    const [botFeed, setBotFeed]           = useState([]);
    const siteId = useMemo(() => { try { return new URL(gleoData.siteUrl).hostname; } catch(e) { return ''; } }, []);
    const node_api_url = 'http://localhost:3000';

    const handleRefreshSov = () => {
        setIsRefreshing(true); setRefreshMsg(null);
        fetch(`${node_api_url}/v1/analytics/sov/refresh`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_id: siteId })
        })
        .then(r => r.json())
        .then(() => fetch(`${node_api_url}/v1/analytics/sov?site_id=${siteId}`).then(r => r.json()))
        .then(r => { setSovData(r.data); setRefreshMsg('Updated successfully.'); })
        .catch(err => setRefreshMsg('Error: ' + err.message))
        .finally(() => setIsRefreshing(false));
    };

    useEffect(() => {
        fetch(`${node_api_url}/v1/analytics/sov?site_id=${siteId}`).then(r => r.json()).then(r => setSovData(r.data)).catch(() => {});
        fetch(`${node_api_url}/v1/analytics/bot-feed?site_id=${siteId}`).then(r => r.json()).then(r => setBotFeed(r.data || [])).catch(() => {});
        const ch = supabase.channel('bot_hits')
            .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'bot_traffic_logs', filter: `site_id=eq.${siteId}` },
                p => setBotFeed(prev => [p.new, ...prev].slice(0, 20)))
            .subscribe();
        return () => supabase.removeChannel(ch);
    }, [siteId]);

    return (
        <div>
            <div className="gleo-page-header">
                <div>
                    <h1>Analytics</h1>
                    <p className="gleo-page-subtitle">AI visibility and crawler activity</p>
                </div>
            </div>
            <div className="gleo-analytics-grid">
                {/* SOV */}
                <div className="gleo-card">
                    <div className="gleo-card-header">
                        <h3>AI Visibility Share</h3>
                        <button className="gleo-btn gleo-btn-outline" style={{ fontSize: 12, padding: '5px 12px' }}
                            onClick={handleRefreshSov} disabled={isRefreshing}>
                            {isRefreshing ? 'Running…' : 'Refresh'}
                        </button>
                    </div>
                    <div className="gleo-card-body">
                        {refreshMsg && <p style={{ fontSize: 12, color: 'var(--green)', marginBottom: 10 }}>{refreshMsg}</p>}
                        {sovData ? (() => {
                            const shares    = sovData.market_share || [];
                            const yourEntry = shares[0] || { name: 'Your Site', percentage: 0 };
                            const rank      = shares.findIndex(e => e === yourEntry) + 1;
                            return (
                                <div>
                                    <div style={{ textAlign: 'center', padding: '16px 0 20px' }}>
                                        <div style={{ fontSize: 48, fontWeight: 800, color: 'var(--blue)', letterSpacing: -2, lineHeight: 1 }}>
                                            {yourEntry.percentage}%
                                        </div>
                                        <p style={{ fontSize: 12.5, color: 'var(--fg-muted)', marginTop: 5 }}>of AI answers mention your site</p>
                                        <span style={{
                                            display: 'inline-block', marginTop: 8,
                                            fontSize: 11.5, fontWeight: 700,
                                            background: rank === 1 ? '#dcfce7' : '#fef9c3',
                                            color: rank === 1 ? '#166534' : '#7c4e0f',
                                            padding: '3px 12px', borderRadius: 100,
                                        }}>
                                            #{rank} in your industry
                                        </span>
                                    </div>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {shares.map((entry, i) => (
                                            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <span style={{ fontSize: 11, width: 18, color: 'var(--fg-muted)', textAlign: 'right', fontWeight: 600 }}>#{i+1}</span>
                                                <span style={{ fontSize: 13, width: 120, fontWeight: i === 0 ? 700 : 400, color: i === 0 ? 'var(--fg)' : 'var(--fg-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {i === 0 ? 'Your Site' : entry.name}
                                                </span>
                                                <div style={{ flex: 1, background: '#f1f5f9', borderRadius: 4, height: 7, overflow: 'hidden' }}>
                                                    <div style={{ width: `${entry.percentage}%`, height: '100%', background: i === 0 ? 'var(--blue)' : '#cbd5e1', borderRadius: 4, transition: 'width 1s ease' }}/>
                                                </div>
                                                <span style={{ fontSize: 12.5, fontWeight: 700, width: 32, textAlign: 'right', color: i === 0 ? 'var(--blue)' : 'var(--fg-muted)' }}>{entry.percentage}%</span>
                                            </div>
                                        ))}
                                    </div>
                                    <p style={{ fontSize: 11, color: 'var(--fg-muted)', marginTop: 14, lineHeight: 1.5, borderTop: '1px solid var(--border-lt)', paddingTop: 10 }}>
                                        Simulated from AI queries in your industry.
                                    </p>
                                </div>
                            );
                        })() : (
                            <div className="gleo-no-data-v2">
                                <Zap size={28}/>
                                <p>No data yet. Click Refresh to run your first AI visibility analysis.</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Bot feed */}
                <div className="gleo-card">
                    <div className="gleo-card-header">
                        <h3>Live AI Crawler Activity</h3>
                        <span className="gleo-card-meta">Real-time</span>
                    </div>
                    <div className="gleo-card-body">
                        <p style={{ fontSize: 12.5, color: 'var(--fg-muted)', marginBottom: 14, marginTop: -4 }}>
                            See when AI bots like ChatGPT and Perplexity visit your site.
                        </p>
                        <div className="gleo-bot-feed">
                            {botFeed.length > 0 ? botFeed.map((hit, i) => (
                                <div key={hit.id || i} className="gleo-bot-hit-item">
                                    <div className="gleo-bot-icon-wrap"><ShieldCheck size={14}/></div>
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
                                    <Activity size={28}/>
                                    <p>No bot visits recorded yet. Updates in real time when AI crawlers visit your site.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

// ── Priority section ─────────────────────────────────────────────────────────
const PrioritySection = ({ priority, items, onFix }) => {
    const [open, setOpen] = useState(priority === 'critical' || priority === 'high' || priority === 'medium');
    if (!items || items.length === 0) return null;
    const labels   = { critical: 'Critical Issues', high: 'High Priority', medium: 'Improvements', positive: 'Positive Signals' };
    const dotClass = { critical: 'dot-critical', high: 'dot-high', medium: 'dot-medium', positive: 'dot-positive' };
    return (
        <div className="gleo-priority-section">
            <div className="gleo-priority-header" onClick={() => setOpen(!open)}>
                <span className={`gleo-priority-dot ${dotClass[priority]}`}></span>
                <span className="gleo-priority-title">{labels[priority] || priority}</span>
                <span className="gleo-priority-count">{items.length}</span>
                <IconChevron open={open}/>
            </div>
            {open && (
                <div className="gleo-priority-items">
                    {items.map((item, i) => (
                        <div key={i} className="gleo-rec-card">
                            <div className="gleo-rec-card-body">
                                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 3 }}>
                                    <strong>{item.area}</strong>
                                    {item.maxScore !== undefined && (
                                        <span className="gleo-rec-score-tag"
                                            style={{ color: item.score === item.maxScore ? 'var(--green)' : item.score > 0 ? 'var(--amber)' : 'var(--red)' }}>
                                            {item.score}/{item.maxScore}
                                        </span>
                                    )}
                                </div>
                                <p>{item.message}</p>
                            </div>
                            <div style={{ flexShrink: 0, paddingTop: 2 }}>
                                {item.fixType ? (
                                    <button className="gleo-btn gleo-btn-primary"
                                        style={{ fontSize: 12, padding: '5px 12px' }}
                                        onClick={() => onFix(item.fixType, item)}
                                        disabled={item.applied || item.applying}>
                                        {item.applied ? 'Applied' : item.applying ? 'Fixing…' : 'Fix'}
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

// ── Scan Complete Modal ──────────────────────────────────────────────────────
const ScanCompleteModal = ({ resultCount, scanResults, onClose }) => {
    const topIssues = [];
    for (const r of (scanResults || [])) {
        if (!r.result) continue;
        for (const rec of (r.result.recommendations || [])) {
            if (topIssues.length >= 4) break;
            if (rec.priority === 'critical' || rec.priority === 'high')
                topIssues.push({ ...rec, postTitle: r.result.title || `Post #${r.post_id}` });
        }
        if (topIssues.length >= 4) break;
    }
    const totalFixes  = (scanResults || []).reduce((s, r) => s + (r.result?.recommendations?.length || 0), 0);
    const autoFixable = (scanResults || []).reduce((s, r) =>
        s + (r.result?.recommendations || []).filter(rec => AREA_TO_FIX[rec.area]).length, 0);

    return (
        <div className="gleo-modal-backdrop" onClick={onClose}>
            <div className="gleo-modal gleo-scan-modal" onClick={e => e.stopPropagation()}>
                <div className="gleo-scan-modal-header">
                    <h3>Analysis complete</h3>
                    <p>
                        Found <strong>{totalFixes} ways to improve</strong> across {resultCount} post{resultCount !== 1 ? 's' : ''}.{' '}
                        <strong style={{ color: 'var(--green)' }}>{autoFixable} can be fixed automatically.</strong>
                    </p>
                </div>
                {topIssues.length > 0 && (
                    <div style={{ marginBottom: 16 }}>
                        <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--fg-muted)', marginBottom: 8 }}>
                            Top findings
                        </p>
                        {topIssues.map((issue, i) => (
                            <div key={i} className="gleo-issue-row">
                                <span className={`gleo-issue-badge ${issue.priority}`}>{issue.priority}</span>
                                <div>
                                    <span className="gleo-issue-area">{issue.area}</span>
                                    <span className="gleo-issue-msg">{issue.message}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
                <button className="gleo-btn gleo-btn-primary"
                    style={{ width: '100%', padding: '10px 0', fontSize: 13.5 }} onClick={onClose}>
                    View results and fix issues
                </button>
                <p style={{ fontSize: 11.5, color: 'var(--fg-muted)', textAlign: 'center', marginTop: 10 }}>
                    Expand any post below to see its full report.
                </p>
            </div>
        </div>
    );
};

// ── Site Preview ─────────────────────────────────────────────────────────────
const SitePreview = ({ url, onClose, onApplyAll, applyingAll, allApplied, items, onFix }) => {
    const [iframeKey, setIframeKey]       = useState(Date.now());
    const [iframeLoaded, setIframeLoaded] = useState(false);

    useEffect(() => {
        if (!applyingAll && allApplied) { setIframeKey(Date.now()); setIframeLoaded(false); }
    }, [applyingAll, allApplied]);

    const issueItems = (items || []).filter(i => i.priority === 'critical' || i.priority === 'high');
    const otherItems = (items || []).filter(i => i.priority !== 'critical' && i.priority !== 'high');

    return (
        <div className="gleo-preview-overlay">
            <div className="gleo-preview-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                    <h4>Live Preview</h4>
                    {!allApplied ? (
                        <button className="gleo-btn gleo-btn-primary" style={{ fontSize: 12.5 }}
                            onClick={onApplyAll} disabled={applyingAll}>
                            {applyingAll ? 'Applying fixes…' : 'Apply all auto-fixes'}
                        </button>
                    ) : (
                        <span style={{ color: '#22c55e', fontWeight: 600, fontSize: 13 }}>All fixes applied</span>
                    )}
                </div>
                <button className="gleo-btn" style={{ background: '#1e293b', color: '#94a3b8', border: '1px solid #334155', fontSize: 12.5 }}
                    onClick={onClose}>Close</button>
            </div>
            <div className="gleo-preview-body">
                <div className="gleo-preview-sidebar">
                    {issueItems.length === 0 && otherItems.length === 0 && (
                        <p style={{ color: '#64748b', fontSize: 13, lineHeight: 1.5 }}>No issues to display.</p>
                    )}
                    {issueItems.length > 0 && (
                        <>
                            <p className="gleo-preview-sidebar-label">Issues</p>
                            {issueItems.map((item, i) => (
                                <div key={i} className={`gleo-preview-issue ${item.priority === 'critical' ? 'crit' : 'high'}`}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <span className="gleo-preview-issue-title">{item.area}</span>
                                        <span className={`gleo-preview-issue-badge ${item.priority === 'critical' ? 'badge-crit' : 'badge-high'}`}>{item.priority}</span>
                                    </div>
                                    <p className="gleo-preview-issue-msg">{item.message}</p>
                                    {item.fixType && !item.applied && (
                                        <button className="gleo-preview-fix-btn" onClick={() => onFix(item.fixType)}>Fix this</button>
                                    )}
                                    {item.applied && <p className="gleo-preview-fixed">Fixed</p>}
                                </div>
                            ))}
                        </>
                    )}
                    {otherItems.length > 0 && (
                        <>
                            <p className="gleo-preview-sidebar-label" style={{ marginTop: 16 }}>Improvements</p>
                            {otherItems.map((item, i) => (
                                <div key={i} className="gleo-preview-issue" style={{ borderColor: '#334155' }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                        <span className="gleo-preview-issue-title" style={{ fontSize: 12 }}>{item.area}</span>
                                        {item.fixType && !item.applied && (
                                            <button className="gleo-preview-fix-btn"
                                                style={{ background: 'transparent', color: '#64748b', border: '1px solid #334155', marginTop: 0 }}
                                                onClick={() => onFix(item.fixType)}>Fix</button>
                                        )}
                                        {item.applied && <span className="gleo-preview-fixed">Fixed</span>}
                                    </div>
                                </div>
                            ))}
                        </>
                    )}
                </div>
                <div className="gleo-preview-iframe-wrap">
                    {(applyingAll || !iframeLoaded) && (
                        <div className="gleo-preview-loading">
                            <div className="gleo-spinner"></div>
                            <p>{applyingAll ? 'Applying fixes…' : 'Loading preview…'}</p>
                        </div>
                    )}
                    <iframe key={iframeKey} src={`${url}&nocache=${iframeKey}`}
                        onLoad={() => setIframeLoaded(true)}
                        style={{ width: '100%', height: '100%', border: 'none', position: 'absolute', inset: 0 }}
                        title="Site Preview"/>
                </div>
            </div>
        </div>
    );
};

// ── Report Card ──────────────────────────────────────────────────────────────
const GeoReportCard = ({ report }) => {
    const { post_id, result } = report;
    const [expanded, setExpanded]             = useState(false);
    const [appliedTypes, setAppliedTypes]     = useState({});
    const [applyingTypes, setApplyingTypes]   = useState({});
    const [toasts, setToasts]                 = useState([]);
    const [modal, setModal]                   = useState(null);
    const [showPreview, setShowPreview]       = useState(false);
    const [isApplyingAll, setIsApplyingAll]   = useState(false);
    const [showSchema, setShowSchema]         = useState(false);
    const hasAutoOpenedPreview                = useRef(false);

    const siteUrl = typeof gleoData !== 'undefined' ? gleoData.siteUrl : '';
    const postUrl = siteUrl ? `${siteUrl}/?p=${post_id}` : '';

    useEffect(() => {
        if (expanded && !hasAutoOpenedPreview.current && postUrl) {
            hasAutoOpenedPreview.current = true;
            setShowPreview(true);
        }
    }, [expanded, postUrl]);

    if (!result) return null;

    const addToast    = msg => { const id = Date.now(); setToasts(p => [...p, { id, message: msg }]); };
    const removeToast = id  => setToasts(p => p.filter(t => t.id !== id));

    const buildItems = () => {
        const items = [];
        if (result.json_ld_schema && !result.content_signals?.has_schema) {
            items.push({ priority: 'critical', area: 'Schema Markup', message: 'Inject AI-generated JSON-LD structured data.', fixType: 'schema', score: 0, maxScore: 10 });
        }
        if (result.recommendations) {
            for (const rec of result.recommendations) items.push({ ...rec, fixType: AREA_TO_FIX[rec.area] || null });
        }
        return items.map(item => ({
            ...item,
            applied:  item.fixType ? !!appliedTypes[item.fixType]  : false,
            applying: item.fixType ? !!applyingTypes[item.fixType] : false,
        }));
    };

    const allItems = buildItems();
    const grouped  = {};
    for (const item of allItems) { const p = item.priority || 'medium'; if (!grouped[p]) grouped[p] = []; grouped[p].push(item); }

    const doApply = (fixType, userInput) => {
        const config = FIX_CONFIG[fixType];
        setApplyingTypes(p => ({ ...p, [fixType]: true }));
        const data = { post_id, type: fixType, enabled: true };
        if (userInput !== undefined) data.user_input = userInput;
        apiFetch({ path: '/gleo/v1/apply', method: 'POST', data })
            .then(() => { setAppliedTypes(p => ({ ...p, [fixType]: true })); addToast(config?.successMsg || `${fixType} applied.`); })
            .catch(err => addToast(`Failed: ${err.message || 'Unknown error'}`))
            .finally(() => setApplyingTypes(p => ({ ...p, [fixType]: false })));
    };

    const handleFix = fixType => {
        const config = FIX_CONFIG[fixType];
        if (!config) return;
        if (config.needsInput) setModal({ fixType, title: config.label, prompt: config.prompt, inputType: config.inputType });
        else doApply(fixType);
    };

    const handleApplyAll = () => {
        setIsApplyingAll(true);
        const promises = [];
        const names = [];
        for (const item of allItems) {
            if (item.fixType && !item.applied && !FIX_CONFIG[item.fixType]?.needsInput) {
                names.push(FIX_CONFIG[item.fixType]?.label || item.area);
                setApplyingTypes(p => ({ ...p, [item.fixType]: true }));
                promises.push(
                    apiFetch({ path: '/gleo/v1/apply', method: 'POST', data: { post_id, type: item.fixType, enabled: true } })
                        .then(() => setAppliedTypes(p => ({ ...p, [item.fixType]: true })))
                        .finally(() => setApplyingTypes(p => ({ ...p, [item.fixType]: false })))
                );
            }
        }
        if (promises.length > 0) {
            Promise.all(promises)
                .then(() => addToast(`Applied: ${names.join(', ')}`))
                .catch(() => addToast('Some fixes failed. Please try again.'))
                .finally(() => setIsApplyingAll(false));
        } else {
            setIsApplyingAll(false);
        }
    };

    const allAutoFixed = allItems.filter(i => i.fixType && !FIX_CONFIG[i.fixType]?.needsInput).every(i => i.applied);
    const score        = result.geo_score;
    const issueCount   = allItems.filter(i => i.priority === 'critical' || i.priority === 'high').length;

    return (
        <div className="gleo-report-card">
            {toasts.length > 0 && (
                <div className="gleo-toast-container">
                    {toasts.map(t => <SuccessToast key={t.id} message={t.message} onDismiss={() => removeToast(t.id)}/>)}
                </div>
            )}

            <div className="gleo-report-header" onClick={() => setExpanded(!expanded)}>
                <span className={`gleo-score-chip ${scoreChipClass(score)}`}>{score}</span>
                <div className="gleo-report-title">
                    <h3>{result.title || `Post #${post_id}`}</h3>
                    {result.content_signals?.word_count !== undefined && (
                        <p className="gleo-post-meta">{result.content_signals.word_count} words &middot; {issueCount} issue{issueCount !== 1 ? 's' : ''}</p>
                    )}
                </div>
                <div className="gleo-score-bar">
                    <div className="gleo-score-bar-track">
                        <div className="gleo-score-bar-fill" style={{ width: `${score}%`, background: scoreBarColor(score) }}/>
                    </div>
                    <span style={{ fontSize: 11.5, color: 'var(--fg-muted)', width: 28 }}>{result.brand_inclusion_rate}/10</span>
                </div>
                <IconChevron open={expanded}/>
            </div>

            {expanded && (
                <div className="gleo-report-body">
                    {result.json_ld_schema && appliedTypes['schema'] && (
                        <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, padding: '14px 16px', marginBottom: 16 }}>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                <strong style={{ color: '#166534', fontSize: 13.5 }}>JSON-LD schema applied to LLMs.txt</strong>
                                <button className="gleo-btn gleo-btn-outline" style={{ fontSize: 12, padding: '4px 10px' }}
                                    onClick={() => setShowSchema(!showSchema)}>
                                    {showSchema ? 'Hide' : 'View schema'}
                                </button>
                            </div>
                            {showSchema && (
                                <pre style={{ background: '#1e293b', color: '#34d399', padding: '12px', borderRadius: 6, fontSize: 12, overflowX: 'auto', margin: '12px 0 0' }}>
                                    <code>{JSON.stringify(result.json_ld_schema, null, 2)}</code>
                                </pre>
                            )}
                        </div>
                    )}

                    {result.content_signals && (
                        <div className="gleo-section">
                            <h4>Content Signals</h4>
                            <div className="gleo-signals-grid">
                                <Signal label="Word Count"  value={result.content_signals.word_count}/>
                                <Signal label="Headings"    value={result.content_signals.heading_count}   good={result.content_signals.has_headings}   fixed={!!appliedTypes['structure']}/>
                                <Signal label="Lists"       value={result.content_signals.list_item_count}  good={result.content_signals.has_lists}      fixed={!!appliedTypes['formatting']}/>
                                <Signal label="Images"      value={result.content_signals.image_count}     good={result.content_signals.has_images}/>
                                <Signal label="Citations"   value={result.content_signals.citation_count}  good={result.content_signals.has_citations}  fixed={!!appliedTypes['credibility']}/>
                                <Signal label="FAQ"         value={result.content_signals.has_faq || appliedTypes['faq'] ? 'Yes' : 'No'}                good={result.content_signals.has_faq}        fixed={!!appliedTypes['faq']}/>
                                <Signal label="Statistics"  value={result.content_signals.has_statistics || appliedTypes['authority'] ? 'Yes' : 'No'}   good={result.content_signals.has_statistics} fixed={!!appliedTypes['authority']}/>
                                <Signal label="Schema"      value={result.content_signals.has_schema || appliedTypes['schema'] ? 'Yes' : 'No'}           good={result.content_signals.has_schema}     fixed={!!appliedTypes['schema']}/>
                            </div>
                        </div>
                    )}

                    <PostHistoryChart postId={post_id}/>

                    <div className="gleo-apply-all-row">
                        <button className="gleo-btn gleo-btn-primary" onClick={handleApplyAll} disabled={allAutoFixed}>
                            {allAutoFixed ? 'All auto-fixes applied' : 'Apply all auto-fixes'}
                        </button>
                        {postUrl && (
                            <button className="gleo-btn gleo-btn-outline" onClick={() => setShowPreview(!showPreview)}>
                                {showPreview ? 'Hide preview' : 'Preview on site'}
                            </button>
                        )}
                        <span className="gleo-apply-hint">Fixes needing input still require a click.</span>
                    </div>

                    {showPreview && postUrl && (
                        <SitePreview url={postUrl} onClose={() => setShowPreview(false)}
                            onApplyAll={handleApplyAll} applyingAll={isApplyingAll} allApplied={allAutoFixed}
                            items={allItems} onFix={handleFix}/>
                    )}

                    <div className="gleo-section">
                        <h4>Recommendations</h4>
                        {['critical', 'high', 'medium', 'positive'].map(p => (
                            <PrioritySection key={p} priority={p} items={grouped[p]} onFix={handleFix}/>
                        ))}
                    </div>
                </div>
            )}

            {modal && (
                <InputModal title={modal.title} prompt={modal.prompt} inputType={modal.inputType}
                    onSubmit={input => { doApply(modal.fixType, input); setModal(null); }}
                    onCancel={() => setModal(null)}/>
            )}
        </div>
    );
};

// ── Settings panel ────────────────────────────────────────────────────────────
const SettingsPanel = ({ clientId, setClientId, secretKey, setSecretKey, onSave, isSaving, saveStatus, overrideSchema, setOverrideSchema }) => (
    <div>
        <div className="gleo-page-header">
            <div>
                <h1>Settings</h1>
                <p className="gleo-page-subtitle">API credentials and plugin configuration</p>
            </div>
        </div>
        {saveStatus && <div className={`gleo-notice ${saveStatus.type}`}>{saveStatus.message}</div>}
        {seoPluginActive && (
            <div className="gleo-seo-warning" style={{ marginBottom: 16 }}>
                <strong>{seoPluginName} detected.</strong> You can override its schema with Gleo's AI-optimized version.
                <div style={{ marginTop: 10, display: 'flex', alignItems: 'center', gap: 10 }}>
                    <input type="checkbox" id="gleo-override" checked={overrideSchema}
                        onChange={e => {
                            setOverrideSchema(e.target.checked);
                            apiFetch({ path: '/wp/v2/settings', method: 'POST', data: { gleo_override_schema: e.target.checked } });
                        }}
                        style={{ accentColor: 'var(--blue)', width: 15, height: 15, cursor: 'pointer' }}/>
                    <label htmlFor="gleo-override" style={{ fontSize: 13, color: 'var(--fg-mid)', cursor: 'pointer' }}>
                        Global schema override
                    </label>
                </div>
            </div>
        )}
        <div className="gleo-creds-panel">
            <h3>API Credentials</h3>
            <div className="gleo-field">
                <label>Client ID</label>
                <input className="gleo-input" type="text" value={clientId} onChange={e => setClientId(e.target.value)}/>
            </div>
            <div className="gleo-field">
                <label>Secret Key</label>
                <input className="gleo-input" type="password" value={secretKey} onChange={e => setSecretKey(e.target.value)}/>
            </div>
            <button className="gleo-btn gleo-btn-primary" onClick={onSave} disabled={isSaving}>
                {isSaving ? 'Saving…' : 'Save settings'}
            </button>
        </div>
    </div>
);

// ── Main App ─────────────────────────────────────────────────────────────────
const App = () => {
    const [activeTab, setActiveTab]             = useState('dashboard');
    const [clientId, setClientId]               = useState('');
    const [secretKey, setSecretKey]             = useState('');
    const [isSaving, setIsSaving]               = useState(false);
    const [saveStatus, setSaveStatus]           = useState(null);
    const [isScanning, setIsScanning]           = useState(false);
    const [scanProgress, setScanProgress]       = useState(0);
    const [fakeProgress, setFakeProgress]       = useState(0);
    const [scanResults, setScanResults]         = useState([]);
    const [overrideSchema, setOverrideSchema]   = useState(false);
    const [availablePosts, setAvailablePosts]   = useState([]);
    const [selectedPosts, setSelectedPosts]     = useState([]);
    const [isLoadingPosts, setIsLoadingPosts]   = useState(true);
    const [showScanModal, setShowScanModal]     = useState(false);
    const scanJustStarted                       = useRef(false);
    const progressIntervalRef                   = useRef(null);

    // Simulated progress
    useEffect(() => {
        if (isScanning) {
            setFakeProgress(8);
            progressIntervalRef.current = setInterval(() => {
                setFakeProgress(p => p >= 88 ? p : p + Math.max(0.4, (88 - p) * 0.04));
            }, 700);
        } else {
            clearInterval(progressIntervalRef.current);
            setFakeProgress(0);
        }
        return () => clearInterval(progressIntervalRef.current);
    }, [isScanning]);

    useEffect(() => {
        apiFetch({ path: '/wp/v2/settings' }).then(s => {
            setClientId(s.gleo_client_id || '');
            setSecretKey(s.gleo_secret_key || '');
            setOverrideSchema(s.gleo_override_schema || false);
        });
        apiFetch({ path: '/wp/v2/posts?per_page=20&status=publish' })
            .then(posts => { setAvailablePosts(posts); setSelectedPosts(posts.slice(0, 5).map(p => p.id)); setIsLoadingPosts(false); })
            .catch(() => setIsLoadingPosts(false));
        checkScanStatus();
    }, []);

    const checkScanStatus = () => {
        apiFetch({ path: '/gleo/v1/scan/status' })
            .then(res => {
                setIsScanning(res.is_scanning); setScanProgress(res.progress);
                if (res.results?.length > 0) {
                    setScanResults(res.results);
                    if (!res.is_scanning && scanJustStarted.current) {
                        setShowScanModal(true); scanJustStarted.current = false;
                    }
                }
                if (res.is_scanning) setTimeout(checkScanStatus, 3000);
            }).catch(() => {});
    };

    const handleSave = () => {
        setIsSaving(true); setSaveStatus(null);
        apiFetch({ path: '/wp/v2/settings', method: 'POST', data: { gleo_client_id: clientId, gleo_secret_key: secretKey } })
            .then(() => setSaveStatus({ type: 'success', message: 'Settings saved.' }))
            .catch(err => setSaveStatus({ type: 'error', message: err.message || 'Error saving.' }))
            .finally(() => setIsSaving(false));
    };

    const handleScan = () => {
        if (selectedPosts.length === 0) { setSaveStatus({ type: 'error', message: 'Select at least one post.' }); return; }
        scanJustStarted.current = true;
        setIsScanning(true); setScanProgress(0); setScanResults([]); setSaveStatus(null);
        apiFetch({ path: '/gleo/v1/scan/start', method: 'POST', data: { post_ids: selectedPosts } })
            .then(res => { setSaveStatus({ type: 'success', message: res.message }); checkScanStatus(); })
            .catch(err => { setSaveStatus({ type: 'error', message: err.message || 'Error starting scan.' }); setIsScanning(false); });
    };

    const avgScore      = scanResults.length ? Math.round(scanResults.reduce((s, r) => s + (r.result?.geo_score || 0), 0) / scanResults.length) : null;
    const totalIssues   = scanResults.reduce((s, r) => s + (r.result?.recommendations?.filter(i => i.priority === 'critical' || i.priority === 'high').length || 0), 0);
    // Posts scoring 70+ are considered "optimized"
    const optimizedCount = scanResults.filter(r => (r.result?.geo_score || 0) >= 70).length;
    // Auto-fixable = recommendations that have a mapped fixType and don't require user input
    const quickWins     = scanResults.reduce((s, r) => s + (r.result?.recommendations || []).filter(rec => {
        const ft = AREA_TO_FIX[rec.area];
        return ft && FIX_CONFIG[ft] && !FIX_CONFIG[ft].needsInput;
    }).length, 0);
    // Coverage = % of all published posts that have been scanned
    const coveragePct   = availablePosts.length > 0 ? Math.round((scanResults.length / availablePosts.length) * 100) : 0;
    const siteHostname = typeof gleoData !== 'undefined' ? (() => { try { return new URL(gleoData.siteUrl).hostname; } catch(e) { return 'your site'; } })() : 'your site';

    return (
        <div className="gleo-dashboard">
            {/* Sidebar */}
            <aside className="gleo-sidebar">
                <div className="gleo-sidebar-top">
                    <div className="gleo-logo">gl<em>eo</em></div>
                    <div className="gleo-workspace">
                        <span className="gleo-ws-dot"></span>
                        <span className="gleo-ws-name">{siteHostname}</span>
                    </div>
                </div>
                <nav className="gleo-nav">
                    <div className="gleo-nav-group">Overview</div>
                    <div className={`gleo-nav-item ${activeTab === 'dashboard' ? 'active' : ''}`} onClick={() => setActiveTab('dashboard')}>
                        <IconDashboard/>
                        Dashboard
                        {avgScore !== null && <span className="gleo-nav-badge blue">{avgScore}</span>}
                    </div>
                    <div className={`gleo-nav-item ${activeTab === 'scan' ? 'active' : ''}`} onClick={() => setActiveTab('scan')}>
                        <IconScan/>
                        Scan Now
                        {totalIssues > 0 && <span className="gleo-nav-badge">{totalIssues}</span>}
                    </div>
                    <div className={`gleo-nav-item ${activeTab === 'analytics' ? 'active' : ''}`} onClick={() => setActiveTab('analytics')}>
                        <IconAnalytics/>
                        Analytics
                    </div>
                    <div className="gleo-nav-group">Account</div>
                    <div className={`gleo-nav-item ${activeTab === 'settings' ? 'active' : ''}`} onClick={() => setActiveTab('settings')}>
                        <IconSettings/>
                        Settings
                    </div>
                </nav>
            </aside>

            {/* Main content */}
            <main className="gleo-main">

                {/* Dashboard */}
                {activeTab === 'dashboard' && (
                    <div>
                        <div className="gleo-page-header">
                            <div>
                                <h1>Dashboard</h1>
                                <p className="gleo-page-subtitle">AI visibility overview for {siteHostname}</p>
                            </div>
                            <div className="gleo-header-actions">
                                <button className="gleo-btn gleo-btn-outline" onClick={() => setActiveTab('analytics')}>View Analytics</button>
                                <button className="gleo-btn gleo-btn-primary" onClick={() => setActiveTab('scan')}>Run Scan</button>
                            </div>
                        </div>

                        {scanResults.length > 0 && (
                            <>
                                <div className="gleo-section-label">Performance</div>
                                <div className="gleo-kpi-grid">
                                    {/* 1. Average GEO Score — the single north-star health number */}
                                    <div className="gleo-kpi">
                                        <div className="gleo-kpi-label">Avg GEO Score</div>
                                        <div className="gleo-kpi-value accent">{avgScore !== null ? `${avgScore}/100` : '—'}</div>
                                        <div className={`gleo-kpi-delta ${avgScore >= 70 ? 'up' : avgScore >= 40 ? 'warn' : 'down'}`}>
                                            {avgScore >= 70 ? 'Well optimized' : avgScore >= 40 ? 'Room to improve' : 'Needs attention'}
                                        </div>
                                    </div>
                                    {/* 2. Posts Optimized — shows progress at a glance */}
                                    <div className="gleo-kpi">
                                        <div className="gleo-kpi-label">Posts Optimized</div>
                                        <div className="gleo-kpi-value">{optimizedCount}<span style={{ fontSize: 14, fontWeight: 500, color: 'var(--fg-muted)' }}>/{scanResults.length}</span></div>
                                        <div className={`gleo-kpi-delta ${optimizedCount === scanResults.length ? 'up' : 'warn'}`}>
                                            {optimizedCount === scanResults.length ? 'All scoring 70+' : `${scanResults.length - optimizedCount} below threshold`}
                                        </div>
                                    </div>
                                    {/* 3. Quick Wins — immediately actionable, high motivation */}
                                    <div className="gleo-kpi">
                                        <div className="gleo-kpi-label">Quick Wins</div>
                                        <div className="gleo-kpi-value" style={{ color: quickWins > 0 ? 'var(--blue)' : 'var(--green)' }}>{quickWins}</div>
                                        <div className={`gleo-kpi-delta ${quickWins > 0 ? 'up' : 'up'}`}>
                                            {quickWins > 0 ? 'Auto-fixable now' : 'Nothing left to fix'}
                                        </div>
                                    </div>
                                    {/* 4. Content Coverage — shows how much of the site has been analyzed */}
                                    <div className="gleo-kpi">
                                        <div className="gleo-kpi-label">Content Coverage</div>
                                        <div className="gleo-kpi-value">{coveragePct}<span style={{ fontSize: 14, fontWeight: 500, color: 'var(--fg-muted)' }}>%</span></div>
                                        <div className="gleo-kpi-delta" style={{ color: 'var(--fg-muted)' }}>
                                            {scanResults.length} of {availablePosts.length} posts scanned
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}

                        {scanResults.length > 0 ? (
                            <>
                                <div className="gleo-section-label" style={{ marginBottom: 10 }}>
                                    Content &mdash; {scanResults.length} post{scanResults.length !== 1 ? 's' : ''}
                                </div>
                                {scanResults.map(r => <GeoReportCard key={r.post_id} report={r}/>)}
                            </>
                        ) : (
                            <div style={{ textAlign: 'center', padding: '64px 24px', color: 'var(--fg-muted)' }}>
                                <Globe size={32} style={{ marginBottom: 14, opacity: 0.35 }}/>
                                <p style={{ fontSize: 15, fontWeight: 600, color: 'var(--fg)', marginBottom: 6 }}>No scan results yet</p>
                                <p style={{ fontSize: 13, marginBottom: 20, lineHeight: 1.5 }}>Run a scan to see GEO scores and recommendations.</p>
                                <button className="gleo-btn gleo-btn-primary" onClick={() => setActiveTab('scan')}>Run your first scan</button>
                            </div>
                        )}

                        {showScanModal && (
                            <ScanCompleteModal resultCount={scanResults.length} scanResults={scanResults}
                                onClose={() => setShowScanModal(false)}/>
                        )}
                    </div>
                )}

                {/* Scan */}
                {activeTab === 'scan' && (
                    <div>
                        <div className="gleo-page-header">
                            <div>
                                <h1>Scan</h1>
                                <p className="gleo-page-subtitle">Analyze posts for AI search optimization</p>
                            </div>
                        </div>
                        {saveStatus && <div className={`gleo-notice ${saveStatus.type}`}>{saveStatus.message}</div>}
                        <div className="gleo-card">
                            <div className="gleo-card-header">
                                <h3>Select posts to analyze</h3>
                                <span className="gleo-card-meta">{selectedPosts.length} selected</span>
                            </div>
                            <div className="gleo-card-body">
                                {isLoadingPosts ? (
                                    <p style={{ fontSize: 13, color: 'var(--fg-muted)' }}>Loading posts…</p>
                                ) : (
                                    <div className="gleo-post-list">
                                        {availablePosts.map(post => (
                                            <div key={post.id} className="gleo-post-item"
                                                onClick={() => setSelectedPosts(p => p.includes(post.id) ? p.filter(id => id !== post.id) : [...p, post.id])}>
                                                <input type="checkbox" checked={selectedPosts.includes(post.id)} onChange={() => {}}/>
                                                <label>{post.title.rendered || `Post #${post.id}`}</label>
                                            </div>
                                        ))}
                                        {availablePosts.length === 0 && (
                                            <p style={{ padding: 8, fontSize: 13, color: 'var(--fg-muted)' }}>No published posts found.</p>
                                        )}
                                    </div>
                                )}
                                <button className="gleo-btn gleo-btn-primary"
                                    style={{ width: '100%', padding: '10px 0', fontSize: 13.5, marginTop: 12 }}
                                    onClick={handleScan} disabled={isScanning || selectedPosts.length === 0}>
                                    {isScanning ? 'Analyzing posts…' : `Analyze ${selectedPosts.length} post${selectedPosts.length !== 1 ? 's' : ''}`}
                                </button>
                                {isScanning && (
                                    <div style={{ marginTop: 14 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 5 }}>
                                            <span style={{ fontSize: 12.5, color: 'var(--fg-muted)' }}>Analyzing with AI…</span>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--fg-muted)' }}>
                                                {Math.round(scanProgress > 0 ? scanProgress : fakeProgress)}%
                                            </span>
                                        </div>
                                        <div className="gleo-progress-bar">
                                            <div className="gleo-progress-fill"
                                                style={{ width: `${Math.min(scanProgress > 0 ? scanProgress : fakeProgress, 100)}%` }}/>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Analytics */}
                {activeTab === 'analytics' && <AnalyticsTab/>}

                {/* Settings */}
                {activeTab === 'settings' && (
                    <SettingsPanel
                        clientId={clientId} setClientId={setClientId}
                        secretKey={secretKey} setSecretKey={setSecretKey}
                        onSave={handleSave} isSaving={isSaving} saveStatus={saveStatus}
                        overrideSchema={overrideSchema} setOverrideSchema={setOverrideSchema}/>
                )}
            </main>
        </div>
    );
};

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('gleo-admin-app');
    if (root) render(<App/>, root);
});
