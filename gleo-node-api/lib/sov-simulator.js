const cron = require('node-cron');
const { GoogleGenAI, Type } = require('@google/genai');
const supabase = require('./supabase');

const ai = new GoogleGenAI({
  apiKey: 'AIzaSyChEiT5lG27TR8icKDoi9c7qLXOVUQhMPI',
});

async function runSOVSimulation(siteId) {
  try {
    // 1. Check/Decrement limit
    const { data: sub, error: subError } = await supabase
      .from('subscriptions')
      .select('*')
      .eq('site_id', siteId)
      .single();

    if (subError || !sub) {
      // Create default sub if missing
      await supabase.from('subscriptions').insert([{ site_id: siteId, prompt_limit_used: 0, prompt_limit_max: 40 }]);
    } else if (sub.prompt_limit_used >= sub.prompt_limit_max) {
      console.log(`[SOV] Limit reached for site ${siteId}`);
      return;
    }

    console.log(`[SOV] Running simulation for ${siteId}...`);

    // 2. Perform AI Simulation (Mocking the 10 prompts for now to save user credits, but using Structured Outputs)
    // In production, you would run multiple prompts. Here we do 1 comprehensive one.
    const response = await ai.models.generateContent({
      model: "gemini-2.5-flash",
      contents: [
        { role: "user", parts: [{ text: `Simulate the 'AI Share of Voice' for site_id: ${siteId}. Return a market share breakdown including the brand and its top 3 competitors.` }] }
      ],
      config: {
        systemInstruction: "You are an AI search engine analyst. Analyze the market share of brands in responses to industry-specific queries.",
        responseMimeType: "application/json",
        responseSchema: {
          type: "OBJECT",
          properties: {
            brand_name: { type: "STRING" },
            market_share: {
              type: "ARRAY",
              items: {
                type: "OBJECT",
                properties: {
                  name: { type: "STRING" },
                  percentage: { type: "NUMBER" }
                },
                required: ["name", "percentage"]
              }
            }
          },
          required: ["brand_name", "market_share"]
        }
      }
    });

    let report;
    try {
      report = JSON.parse(response.text);
    } catch (e) {
      console.error('[SOV] Failed to parse Gemini response:', e.message);
      return;
    }

    // 3. Save to database
    await supabase.from('visibility_snapshots').insert([{
      site_id: siteId,
      model_name: "gpt-4o-mini",
      raw_response: report
    }]);

    // 4. Update limit
    await supabase
      .from('subscriptions')
      .update({ prompt_limit_used: (sub?.prompt_limit_used || 0) + 1 })
      .eq('site_id', siteId);

    console.log(`[SOV] Simulation complete for ${siteId}`);

  } catch (err) {
    console.error(`[SOV] Error for ${siteId}:`, err.message);
  }
}

// Weekly job (Sundays at midnight)
function initSOVCron() {
  cron.schedule('0 0 * * 0', async () => {
    console.log('[SOV] Starting weekly global simulation...');
    const { data: subs } = await supabase.from('subscriptions').select('site_id');
    if (subs) {
      for (const sub of subs) {
        await runSOVSimulation(sub.site_id);
      }
    }
  });
  console.log('[SOV] Weekly simulation cron initialized.');
}

module.exports = { initSOVCron, runSOVSimulation };
