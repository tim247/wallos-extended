
document.addEventListener("DOMContentLoaded", function () {
  function updateAiRecommendationNumbers() {
    document.querySelectorAll(".ai-recommendation-item").forEach(function (item, index) {
      const numberSpan = item.querySelector(".ai-recommendation-header h3 > span");
      if (numberSpan) {
        numberSpan.textContent = `${index + 1}. `;
      }
    });
  }

  document.querySelectorAll(".ai-recommendation-item").forEach(function (item) {
    item.addEventListener("click", function () {
      item.classList.toggle("expanded");
    });
  });

  document.querySelectorAll(".delete-ai-recommendation").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const item = el.closest(".ai-recommendation-item");
      const id = item.getAttribute("data-id");

      fetch("endpoints/ai/delete_recommendation.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": window.csrfToken,
        },
        body: JSON.stringify({ id: id }),
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            item.remove();
            updateAiRecommendationNumbers();
            showSuccessMessage(translate("success"));
          } else {
            showErrorMessage(data.message || translate("failed_delete_ai_recommendation"));
          }
        })
        .catch(error => {
          console.error(error);
          showErrorMessage(translate("unknown_error"));
        });
    });
  });

});


/* ---------- household finance dashboard charts ---------- */

document.addEventListener("DOMContentLoaded", function () {
  const data = window.dashboardData;
  if (!data || typeof ApexCharts === "undefined") {
    return;
  }
  const money = apexMoney(data.currency);

  if (data.burdenSplit && data.burdenSplit.length > 1) {
    apexRender("burdenSplitChart", (el, t) => {
      const b = apexBase(el, "donut", 280);
      return {
        ...b,
        series: data.burdenSplit.map((p) => p.y),
        labels: data.burdenSplit.map((p) => `${p.label} (${money(p.y)})`),
        colors: [t.main, ...APEX_PALETTE],
        plotOptions: { pie: { donut: { size: "60%" } } },
        stroke: { width: 0 },
        legend: { ...b.legend, position: "bottom" },
        tooltip: { ...b.tooltip, y: { formatter: money } },
      };
    });
  }

  const value = apexNumber(1);
  (data.meters || []).forEach(function (meter) {
    const labels = Object.keys(meter.monthly);
    apexRender("dashboardMeterChart-" + meter.id, (el, t) => {
      const b = apexBase(el, "bar", 160);
      return {
        ...b,
        series: [{ name: meter.unit, data: labels.map((l) => meter.monthly[l]) }],
        colors: [meter.is_pv ? APEX_GREEN : t.main],
        plotOptions: { bar: { borderRadius: 3, columnWidth: "70%" } },
        fill: { opacity: 0.8 },
        xaxis: { ...b.xaxis, categories: labels, tickAmount: 6, labels: { ...b.xaxis.labels, rotate: 0, hideOverlappingLabels: true } },
        yaxis: { ...b.yaxis, min: 0, tickAmount: 4, labels: { ...b.yaxis.labels, formatter: value } },
        tooltip: { ...b.tooltip, y: { formatter: (v) => value(v) + " " + meter.unit } },
        legend: { show: false },
      };
    });
  });
});
