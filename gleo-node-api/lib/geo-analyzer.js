const axios = require('axios');
const cheerio = require('cheerio');
const { GoogleGenAI } = require('@google/genai');

const TAVILY_API_KEY = process.env.TAVILY_API_KEY;
const TAVILY_SEARCH_URL = 'https://api.tavily.com/search';

const ai = new GoogleGenAI({
  apiKey: 'AIzaSyChEiT5lG27TR8icKDoi9c7qLXOVUQhMPI',
});

/**
 * Generates specifically contextual HTML elements dynamically based on the post.
 */
async function generateContextualAssets(title, content) {
  const $ = cheerio.load(content || '');
  $('script, style, noscript, svg, path, iframe, nav, footer, header, aside').remove();
  const plainText = $('body').text().replace(/\s+/g, ' ').substring(0, 3000).trim();
  
  try {
    const response = await ai.models.generateContent({
      model: "gemini-2.5-flash",
      contents: [
        { role: "user", parts: [{ text: `Article Title: ${title}\nArticle Text excerpt: ${plainText}\n\nGenerate realistic, directly applicable HTML blocks to improve this specific article's Generative Engine Optimization. Output should include a related comparison table, related FAQs, a deeper dive paragraph, a direct Question/Answer block, and a short paragraph of relevant statistics integrated naturally as flowing sentences.` }] }
      ],
      config: {
        systemInstruction: "You are an SEO web content assistant. Generate valid, semantic WordPress blocks (HTML) based strictly on the provided article context. Never generate generic examples or filler; always use details from the provided text. Return as JSON.",
        responseMimeType: "application/json",
        responseSchema: {
          type: "OBJECT",
          properties: {
            data_table_html: { type: "STRING", description: "A comparison table about the article topic wrapped in WP figure and table tags." },
            faq_html: { type: "STRING", description: "An FAQ block (H2, H3, P) with 2-3 questions specific to the article topic." },
            depth_html: { type: "STRING", description: "A 'Deeper Dive' section (H2, P) expanding on the core theme of the article." },
            qa_html: { type: "STRING", description: "A direct 'What is X?' Q&A section summarizing the article's core concept." },
            authority_html: { type: "STRING", description: "A plain paragraph of 2-3 highly relevant statistics and data points woven naturally into flowing sentences — no header, no label, just inline stats." }
          },
          required: ["data_table_html", "faq_html", "depth_html", "qa_html", "authority_html"]
        }
      }
    });
    
    try {
      return JSON.parse(response.text);
    } catch (e) {
      console.error('[GEO] Failed to parse Gemini response:', e.message);
      return null;
    }
  } catch (err) {
    console.error('[GEO] Failed to generate contextual assets:', err.message);
    return null; // Graceful fallback
  }
}

/**
 * Analyzes a single post for Generative Engine Optimization (GEO).
 * Uses Tavily to understand how AI engines see the post's topic,
 * then scores the post and generates actionable recommendations based on live HTML.
 *
 * @param {Object} post - { id, title, content (Live HTML) }
 * @param {string} siteUrl - The WordPress site URL for brand detection
 * @returns {Object} Full GEO report for this post
 */
async function analyzePost(post, siteUrl = '') {
  const { id, title, content } = post;
  console.log(`  [GEO] Analyzing post ${id}: "${title}"`);

  // --- Step 1: Tavily Search - How do AI engines respond to this topic? ---
  let tavilyResults = [];
  try {
    const searchQuery = title.length > 10 ? title : `${title} ${content.substring(0, 100)}`;
    const response = await axios.post(TAVILY_SEARCH_URL, {
      api_key: TAVILY_API_KEY,
      query: searchQuery,
      search_depth: 'advanced',
      include_answer: true,
      include_raw_content: false,
      max_results: 5
    });
    tavilyResults = response.data.results || [];
    console.log(`  [GEO] Tavily returned ${tavilyResults.length} results for "${title}"`);
  } catch (err) {
    console.error(`  [GEO] Tavily search failed for post ${id}:`, err.message);
  }

  // --- Step 2: Brand Inclusion Rate (0-10) ---
  const brandInclusionRate = calculateBrandInclusion(tavilyResults, siteUrl, title);

  // --- Step 3: Content Quality Signals (HTML Parsing) ---
  const contentSignals = analyzeContentSignals(content, title);

  // --- Step 4: GEO Score (0-100) ---
  const geoScore = calculateGeoScore(contentSignals, brandInclusionRate, tavilyResults);

  // --- Step 5: Generate JSON-LD Schema ---
  const jsonLdSchema = generateJsonLd(title, content, siteUrl);
  
  // --- Step 6: Generate Contextual Assets ---
  const contextualAssets = await generateContextualAssets(title, content);

  // --- Step 7: Build Specific Recommendations (Granular Scoring) ---
  const recommendations = generateRecommendations(contentSignals, brandInclusionRate, geoScore);

  return {
    id,
    data: {
      title,
      geo_score: geoScore,
      brand_inclusion_rate: brandInclusionRate,
      json_ld_schema: jsonLdSchema,
      contextual_assets: contextualAssets,
      recommendations,
      content_signals: contentSignals,
      ai_landscape: tavilyResults.slice(0, 3).map(r => ({
        title: r.title,
        url: r.url,
        relevance: r.score ? Math.round(r.score * 100) : null
      }))
    }
  };
}

/**
 * Calculates Brand Inclusion Rate (0-10).
 * Measures how visible the brand/site is in AI-generated search results.
 */
function calculateBrandInclusion(results, siteUrl, postTitle) {
  if (!results.length) return 0;

  let score = 0;
  const siteDomain = siteUrl ? new URL(siteUrl).hostname.replace('www.', '') : '';
  const titleWords = postTitle.toLowerCase().split(/\s+/).filter(w => w.length > 3);

  for (const result of results) {
    const resultText = `${result.title || ''} ${result.content || ''} ${result.url || ''}`.toLowerCase();

    // Direct domain match (strongest signal)
    if (siteDomain && resultText.includes(siteDomain)) {
      score += 3;
    }

    // Title keyword overlap (moderate signal)
    const matchingWords = titleWords.filter(w => resultText.includes(w));
    if (matchingWords.length >= 2) {
      score += 1;
    }
  }

  return Math.min(10, Math.round(score));
}

/**
 * Analyzes the post live HTML for key GEO quality signals using Cheerio.
 */
function analyzeContentSignals(htmlContent, title) {
  const $ = cheerio.load(htmlContent || '');
  
  // Extract plain text for word counts and specific text patterns
  // Remove scripts and styles before extracting text
  $('script, style, noscript, nav, footer, header, aside').remove();
  const plainText = $('body').text() || '';
  const cleanText = plainText.replace(/\s+/g, ' ').trim();
  
  const wordCount = cleanText.split(/\s+/).filter(w => w.length > 0).length;

  // Check for structured elements via proper standard DOM tags
  const headingCount = $('h2, h3, h4, h5, h6').length;
  const hasHeadings = headingCount > 0;
  
  const listCount = $('ul, ol').length;
  const hasList = listCount > 0;
  
  const hasTable = $('table').length > 0;
  
  const imageCount = $('img').length;
  const hasImages = imageCount > 0;
  
  // Re-load original HTML to check head for schema
  const $full = cheerio.load(htmlContent || '');
  const hasSchema = $full('script[type="application/ld+json"]').length > 0;
  
  const hasFAQ = /faq|frequently\s+asked|common\s+questions/i.test(cleanText);
  const hasStatistics = /\d+%|\d+\s*(percent|million|billion)/i.test(cleanText);
  
  const citationCount = $('a[href^="http"]').length;
  const hasCitations = citationCount > 0;

  // Check for answer-ready formatting (direct answers to questions)
  const hasDirectAnswers = /\b(is|are|was|were|can|does|do|will|how|what|why|when|where)\b[^.?]*\?/i.test(cleanText);

  // Paragraph structure
  const paragraphs = $('p').map((i, el) => $(el).text().trim()).get().filter(p => p.length > 20);
  const avgParagraphLength = paragraphs.length > 0
    ? Math.round(paragraphs.reduce((sum, p) => sum + p.split(/\s+/).length, 0) / paragraphs.length)
    : 0;

  return {
    word_count: wordCount,
    has_headings: hasHeadings,
    heading_count: headingCount,
    has_lists: hasList,
    list_item_count: listCount,
    has_table: hasTable,
    has_images: hasImages,
    image_count: imageCount,
    has_schema: hasSchema,
    has_faq: hasFAQ,
    has_statistics: hasStatistics,
    has_citations: hasCitations,
    citation_count: citationCount,
    has_direct_answers: hasDirectAnswers,
    paragraph_count: paragraphs.length,
    avg_paragraph_length: avgParagraphLength
  };
}

/**
 * Calculates the overall GEO score (0-100).
 */
function calculateGeoScore(signals, brandRate, tavilyResults) {
  let score = 0;

  // Content length (max 15 pts)
  if (signals.word_count >= 2000) score += 15;
  else if (signals.word_count >= 1000) score += 10;
  else if (signals.word_count >= 500) score += 5;
  else score += 2;

  // Structure signals (max 25 pts)
  if (signals.has_headings) score += 8;
  if (signals.heading_count >= 3) score += 4;
  if (signals.has_lists) score += 5;
  if (signals.has_table) score += 4;
  if (signals.has_faq) score += 4;

  // Rich media (max 10 pts)
  if (signals.has_images) score += 5;
  if (signals.image_count >= 3) score += 5;

  // Authority signals (max 15 pts)
  if (signals.has_statistics) score += 5;
  if (signals.has_citations) score += 5;
  if (signals.citation_count >= 3) score += 5;

  // Schema (max 10 pts)
  if (signals.has_schema) score += 10;

  // Answer readiness (max 10 pts)
  if (signals.has_direct_answers) score += 5;
  if (signals.avg_paragraph_length <= 60 && signals.avg_paragraph_length > 0) score += 5;

  // Brand visibility from Tavily (max 15 pts)
  score += Math.min(15, Math.round(brandRate * 1.5));

  return Math.min(100, score);
}

// Answer Capsule function removed per user request

/**
 * Generates JSON-LD structured data schema for the post.
 */
function generateJsonLd(title, content, siteUrl) {
  const plainText = (content || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
  const description = plainText.substring(0, 160).trim();
  const wordCount = plainText.split(/\s+/).length;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    'headline': title,
    'description': description,
    'wordCount': wordCount,
    'author': {
      '@type': 'Organization',
      'name': siteUrl ? new URL(siteUrl).hostname : 'Author'
    },
    'datePublished': new Date().toISOString(),
    'mainEntityOfPage': {
      '@type': 'WebPage',
      '@id': siteUrl || ''
    }
  };

  // If content has FAQ-like patterns, add FAQPage schema
  const faqMatches = plainText.match(/(?:what|how|why|when|where|who|can|does|is)\s[^?]*\?/gi);
  if (faqMatches && faqMatches.length >= 2) {
    schema['@type'] = ['Article', 'FAQPage'];
    schema['mainEntity'] = faqMatches.slice(0, 5).map(q => ({
      '@type': 'Question',
      'name': q.trim(),
      'acceptedAnswer': {
        '@type': 'Answer',
        'text': 'See the full article for a detailed answer.'
      }
    }));
  }

  return schema;
}

/**
 * Generates specific, actionable GEO recommendations with individual scores.
 */
function generateRecommendations(signals, brandRate, geoScore) {
  const recs = [];

  // Critical issues
  if (!signals.has_headings) {
    recs.push({
      priority: 'critical',
      area: 'Structure',
      score: 0,
      maxScore: 12,
      message: 'Add semantic headings (H2, H3) to break up your content. AI engines use headings to understand topic hierarchy.'
    });
  }

  if (signals.word_count < 500) {
    recs.push({
      priority: 'critical',
      area: 'Content Depth',
      score: signals.word_count <= 0 ? 0 : Math.round((signals.word_count / 1500) * 15),
      maxScore: 15,
      message: `Your post is only ${signals.word_count} words. AI engines favor comprehensive content. Aim for 1,500+ words with detailed explanations.`
    });
  }

  if (!signals.has_schema) {
    recs.push({
      priority: 'critical',
      area: 'Schema Markup',
      score: 0,
      maxScore: 10,
      message: 'No JSON-LD schema detected. Adding structured data helps AI engines understand your content type and extract key facts.'
    });
  }

  // High priority
  if (!signals.has_lists) {
    recs.push({
      priority: 'high',
      area: 'Formatting',
      score: 0,
      maxScore: 5,
      message: 'Add bullet or numbered lists. AI engines frequently extract list-formatted content for answer generation.'
    });
  }

  if (!signals.has_statistics) {
    recs.push({
      priority: 'high',
      area: 'Authority',
      score: 0,
      maxScore: 5,
      message: 'Include specific statistics, percentages, or data points. Quantitative claims increase citation likelihood by AI engines.'
    });
  }

  if (!signals.has_citations) {
    recs.push({
      priority: 'high',
      area: 'Credibility',
      score: 0,
      maxScore: 15,
      message: 'Add outbound citations to authoritative sources. AI engines weight content higher when it references trusted data.'
    });
  }

  if (brandRate <= 2) {
    recs.push({
      priority: 'high',
      area: 'Brand Visibility',
      score: Math.round(brandRate * 1.5),
      maxScore: 15,
      message: 'Your brand has low visibility in AI search results for this topic. Increase topical authority by publishing more related content and building backlinks.'
    });
  }

  // Medium priority
  if (!signals.has_faq) {
    recs.push({
      priority: 'medium',
      area: 'FAQ Section',
      score: 0,
      maxScore: 4,
      message: 'Add an FAQ section. AI engines frequently pull direct question-answer pairs into generated responses.'
    });
  }

  if (!signals.has_table) {
    recs.push({
      priority: 'medium',
      area: 'Data Tables',
      score: 0,
      maxScore: 4,
      message: 'Consider adding comparison tables or data tables. Structured tabular data is highly extractable by AI engines.'
    });
  }

  if (!signals.has_images || signals.image_count < 2) {
    recs.push({
      priority: 'medium',
      area: 'Visual Content',
      score: signals.has_images ? 5 : 0,
      maxScore: 10,
      message: 'Add more images with descriptive alt text. Multimodal AI engines use image context to enrich answers.'
    });
  }

  if (signals.avg_paragraph_length > 80) {
    recs.push({
      priority: 'medium',
      area: 'Readability',
      score: 0,
      maxScore: 5,
      message: 'Your paragraphs are too long. Break them into shorter chunks (40-60 words). AI engines prefer concise, scannable text blocks.'
    });
  }

  if (!signals.has_direct_answers) {
    recs.push({
      priority: 'medium',
      area: 'Answer Readiness',
      score: 0,
      maxScore: 5,
      message: 'Include direct question-and-answer patterns in your content. This makes it easier for AI engines to extract and cite your content.'
    });
  }

  // Positive feedback
  if (geoScore >= 70) {
    recs.push({
      priority: 'positive',
      area: 'Overall',
      score: geoScore,
      maxScore: 100,
      message: 'Great work! This post has strong GEO fundamentals. Focus on the remaining recommendations to push it higher.'
    });
  }

  return recs;
}

module.exports = { analyzePost };
