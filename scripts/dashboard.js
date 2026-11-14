// Chart text follows the theme's text colour (legible in dark mode)
if (window.Chart) Chart.defaults.color = getComputedStyle(document.body).color;

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
  if (!data || typeof Chart === "undefined") {
    return;
  }

  const mainColor =
    getComputedStyle(document.documentElement).getPropertyValue("--main-color").trim() || "#007BFF";
  const palette = [mainColor, "#f0a04b", "#7c5cbf", "#2e9e44", "#d9534f", "#3aa6b9"];

  const burdenCanvas = document.getElementById("burdenSplitChart");
  if (burdenCanvas && data.burdenSplit && data.burdenSplit.length > 1) {
    new Chart(burdenCanvas.getContext("2d"), {
      type: "doughnut",
      data: {
        labels: data.burdenSplit.map(
          (p) =>
            `${p.label} (${new Intl.NumberFormat(navigator.language, {
              style: "currency",
              currency: data.currency,
            }).format(p.y)})`
        ),
        datasets: [
          {
            data: data.burdenSplit.map((p) => p.y),
            backgroundColor: palette,
            borderWidth: 0,
          },
        ],
      },
      options: {
        maintainAspectRatio: false,
        cutout: "60%",
        plugins: {
          legend: { display: true, position: "bottom" },
          tooltip: {
            callbacks: {
              label: function (context) {
                return (
                  " " +
                  new Intl.NumberFormat(navigator.language, {
                    style: "currency",
                    currency: data.currency,
                  }).format(context.raw)
                );
              },
            },
          },
        },
      },
    });
  }

  (data.meters || []).forEach(function (meter) {
    const canvas = document.getElementById("dashboardMeterChart-" + meter.id);
    if (!canvas) {
      return;
    }
    const labels = Object.keys(meter.monthly);
    const values = labels.map((l) => meter.monthly[l]);
    const color = meter.is_pv ? "#2e9e44" : mainColor;
    new Chart(canvas.getContext("2d"), {
      type: "bar",
      data: {
        labels: labels,
        datasets: [
          {
            data: values,
            backgroundColor: color + "B3",
            borderColor: color,
            borderWidth: 1,
            borderRadius: 4,
          },
        ],
      },
      options: {
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, ticks: { maxTicksLimit: 5 } },
          x: { ticks: { maxTicksLimit: 6 } },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                return (
                  new Intl.NumberFormat(navigator.language, { maximumFractionDigits: 1 }).format(
                    context.parsed.y
                  ) + " " + meter.unit
                );
              },
            },
          },
        },
      },
    });
  });
});
