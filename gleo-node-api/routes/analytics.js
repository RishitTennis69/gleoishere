const express = require('express');
const router = express.Router();
const supabase = require('../lib/supabase');

// POST /v1/analytics/bot-hit
router.post('/bot-hit', async (req, res) => {
  const { site_id, bot_name, request_path, status_code } = req.body;

  if (!site_id || !bot_name) {
    return res.status(400).json({ error: 'Missing site_id or bot_name' });
  }

  const { error } = await supabase
    .from('bot_traffic_logs')
    .insert([{
      site_id,
      bot_name,
      request_path: request_path || '/',
      status_code: status_code || 200,
      timestamp: new Date().toISOString()
    }]);

  if (error) {
    console.error('[BotHit] DB Error:', error.message);
    return res.status(500).json({ error: error.message });
  }

  res.json({ success: true });
});

// GET /v1/analytics/sov
router.get('/sov', async (req, res) => {
  const { site_id } = req.query;
  if (!site_id) return res.status(400).json({ error: 'Missing site_id' });

  const { data, error } = await supabase
    .from('visibility_snapshots')
    .select('*')
    .eq('site_id', site_id)
    .order('timestamp', { ascending: false })
    .limit(1);

  if (error) return res.status(500).json({ error: error.message });
  
  if (!data || data.length === 0) {
    return res.json({ data: null });
  }

  res.json({ data: data[0].raw_response });
});

// GET /v1/analytics/bot-feed
router.get('/bot-feed', async (req, res) => {
  const { site_id } = req.query;
  if (!site_id) return res.status(400).json({ error: 'Missing site_id' });

  const { data, error } = await supabase
    .from('bot_traffic_logs')
    .select('*')
    .eq('site_id', site_id)
    .order('timestamp', { ascending: false })
    .limit(20);

  if (error) return res.status(500).json({ error: error.message });
  res.json({ data: data || [] });
});

// POST /v1/analytics/sov/refresh
router.post('/sov/refresh', async (req, res) => {
  const { site_id } = req.body;
  if (!site_id) return res.status(400).json({ error: 'Missing site_id' });

  // Import runSOVSimulation dynamically to avoid circular dependencies if any
  const { runSOVSimulation } = require('../lib/sov-simulator');

  try {
    await runSOVSimulation(site_id);
    res.json({ success: true, message: 'Deep Refresh Complete.' });
  } catch (err) {
    console.error('[SOV Refresh] Failed:', err.message);
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
