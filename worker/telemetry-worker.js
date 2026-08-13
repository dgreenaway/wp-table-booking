/**
 * getBooked Telemetry Worker
 *
 * Deploy to Cloudflare Workers + D1.
 *
 * Setup:
 *   1. wrangler d1 create getbooked-telemetry
 *   2. Copy the database_id into wrangler.toml
 *   3. wrangler d1 execute getbooked-telemetry --file=schema.sql
 *   4. wrangler deploy
 *   5. Update TB_Telemetry::ENDPOINT in class-tb-telemetry.php with your workers.dev URL
 */

export default {
  async fetch(request, env) {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }

    if (request.method !== 'POST' || new URL(request.url).pathname !== '/ping') {
      return new Response('Not found', { status: 404 });
    }

    let body;
    try {
      body = await request.json();
    } catch {
      return new Response('Bad JSON', { status: 400 });
    }

    const allowed = [
      'plugin_version', 'wp_version', 'php_version',
      'booking_mode', 'table_count', 'reservation_total',
      'locale', 'is_multisite',
    ];

    const row = {};
    for (const key of allowed) {
      if (body[key] !== undefined) row[key] = String(body[key]).slice(0, 100);
    }

    if (!row.plugin_version || !row.wp_version || !row.php_version) {
      return new Response('Missing required fields', { status: 400 });
    }

    const cols   = Object.keys(row).join(', ');
    const placeholders = Object.keys(row).map(() => '?').join(', ');
    const values = Object.values(row);

    await env.DB.prepare(
      `INSERT INTO pings (${cols}) VALUES (${placeholders})`
    ).bind(...values).run();

    return new Response(JSON.stringify({ ok: true }), {
      status: 200,
      headers: { 'Content-Type': 'application/json', ...corsHeaders() },
    });
  },
};

function corsHeaders() {
  return { 'Access-Control-Allow-Origin': '*' };
}
