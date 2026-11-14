// Shared ApexCharts setup for the Wallos Extended modules, matching the statistics page
// (scripts/stats.js): theme colours from the CSS variables, Barlow font, dark mode.

const APEX_PALETTE = ["#f0a04b", "#7c5cbf", "#2e9e44", "#d9534f", "#3aa6b9"]; // after the main colour
const APEX_GREEN = "#2e9e44";
const APEX_ORANGE = "#f0a04b";

function apexTheme() {
  const cs = getComputedStyle(document.documentElement);
  return {
    main: cs.getPropertyValue("--main-color").trim() || "#007BFF",
    text: cs.getPropertyValue("--text-color").trim() || "#202020",
    border: cs.getPropertyValue("--box-border-color").trim() || "#E8E8E8",
    dark: document.body.classList.contains("dark"),
    font: "Barlow, 'Helvetica Neue', Helvetica, sans-serif",
  };
}

function apexMoney(currency) {
  const f = new Intl.NumberFormat(navigator.language, { style: "currency", currency });
  return (v) => (v === null || v === undefined ? "" : f.format(v));
}

function apexNumber(digits) {
  const f = new Intl.NumberFormat(navigator.language, { maximumFractionDigits: digits });
  return (v) => (v === null || v === undefined ? "" : f.format(v));
}

// Base options; the height comes from the container (its CSS sets it), with a fallback.
function apexBase(el, type, fallbackHeight) {
  const t = apexTheme();
  return {
    chart: {
      type,
      height: el.clientHeight || fallbackHeight,
      background: "transparent",
      fontFamily: t.font,
      toolbar: { show: false },
      zoom: { enabled: false },
      animations: { enabled: true, speed: 300 },
    },
    theme: { mode: t.dark ? "dark" : "light" },
    grid: { borderColor: t.border },
    dataLabels: { enabled: false },
    legend: { labels: { colors: t.text }, fontFamily: t.font },
    tooltip: { theme: t.dark ? "dark" : "light", style: { fontFamily: t.font } },
    xaxis: { labels: { style: { colors: t.text } }, axisBorder: { color: t.border }, axisTicks: { color: t.border } },
    yaxis: { labels: { style: { colors: t.text } } },
  };
}

// Renders a chart into the element with the given id; returns the chart or null.
function apexRender(id, buildOptions) {
  const el = document.getElementById(id);
  if (!el || typeof ApexCharts === "undefined") return null;
  const chart = new ApexCharts(el, buildOptions(el, apexTheme()));
  chart.render();
  return chart;
}
