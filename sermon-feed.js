// sermon-feed.js — Orrville C&MA Church
// Loads the latest video from the church's YouTube channel and uses it to
// populate any live-stream/latest-message embed on the page (elements with
// class "yt-live-embed").
//
// Primary source is sermon-feed.php — a same-origin server-side endpoint
// that fetches and parses the channel's YouTube RSS feed directly (no CORS
// concerns, no third-party proxy dependency, cached server-side so repeat
// visits are fast). If that endpoint is ever unreachable (e.g. before it's
// deployed, or a temporary host issue), this falls back to fetching
// rss2json.com directly from the browser as a lightweight safety net.
(function () {
  var CHANNEL_ID = 'UCNTdYwuEPUNamImHjL-fEgg';
  var FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=' + CHANNEL_ID;

  function viaServerEndpoint() {
    return fetch('sermon-feed.php', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('sermon-feed.php bad status'); return r.json(); })
      .then(function (data) {
        if (!data.ok || !data.videos || !data.videos.length) throw new Error('sermon-feed.php bad payload');
        return data.videos.map(function (v) {
          return {
            id: v.id,
            title: v.title || 'Sunday Sermon',
            date: v.date ? new Date(v.date) : null
          };
        }).filter(function (v) { return v.id; });
      });
  }

  // Lightweight fallback only — used if the server endpoint itself can't be
  // reached at all (e.g. not yet deployed). rss2json's free tier can rate
  // limit under heavy use; a free API key (rss2json.com/login, no cost) can
  // be added below as api_key=YOUR_KEY to remove that limit if this fallback
  // path ends up seeing real traffic.
  function viaRss2Json() {
    var params = 'rss_url=' + encodeURIComponent(FEED_URL) + '&count=10&_=' + Date.now();
    return fetch('https://api.rss2json.com/v1/api.json?' + params, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('rss2json bad status'); return r.json(); })
      .then(function (data) {
        if (data.status !== 'ok' || !data.items || !data.items.length) throw new Error('rss2json bad payload');
        return data.items.map(function (item) {
          var m = (item.link || '').match(/[?&]v=([^&]+)/);
          return {
            id: m ? m[1] : null,
            title: item.title || 'Sunday Sermon',
            date: item.pubDate ? new Date(item.pubDate) : null
          };
        }).filter(function (v) { return v.id; });
      });
  }

  function populateLivePlayers(videos) {
    var embeds = document.querySelectorAll('.yt-live-embed');
    if (!embeds.length || !videos.length) return;
    var vId = String(videos[0].id || '');
    if (!/^[A-Za-z0-9_-]{11}$/.test(vId)) return;   // never build an embed URL from an unvalidated ID
    embeds.forEach(function (f) {
      f.src = 'https://www.youtube-nocookie.com/embed/' + vId + '?rel=0';
      f.style.opacity = '1';
    });
  }

  viaServerEndpoint()
    .catch(function () { return viaRss2Json(); })
    .then(populateLivePlayers)
    .catch(function () {
      // Both sources failed — the embed simply keeps its poster/placeholder
      // state. Nothing else on the page depends on this feed.
    });
})();
