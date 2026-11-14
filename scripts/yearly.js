// Yearly overview: due payments per month and the reserve needed to cover them.

// Smallest constant monthly reserve that covers every payment (deposit, then payment).
function yearlyReservePlan(totals, start) {
  let cumulative = 0;
  let monthly = 0;
  totals.forEach((total, i) => {
    cumulative += total;
    monthly = Math.max(monthly, (cumulative - start) / (i + 1));
  });
  cumulative = 0;
  const balances = totals.map((total, i) => {
    cumulative += total;
    return start + (i + 1) * monthly - cumulative;
  });
  return { monthly, balances };
}

function initYearly(data) {
  const formatter = new Intl.NumberFormat(navigator.language, { style: "currency", currency: data.currency });
  const mainColor = getComputedStyle(document.documentElement).getPropertyValue("--main-color").trim() || "#007BFF";
  Chart.defaults.color = getComputedStyle(document.body).color;

  let plan = yearlyReservePlan(data.totals, 0);
  const chart = new Chart(document.getElementById("yearlyChart").getContext("2d"), {
    data: {
      labels: data.labels,
      datasets: [
        { type: "bar", label: translate("yearly_due"), data: data.totals, backgroundColor: mainColor + "B3", order: 2 },
        {
          type: "line",
          label: translate("yearly_reserve_balance"),
          data: plan.balances,
          borderColor: "#2e9e44",
          backgroundColor: "transparent",
          tension: 0.15,
          pointRadius: 3,
          order: 1,
        },
      ],
    },
    options: {
      maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { callback: (value) => formatter.format(value) } } },
      plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${formatter.format(ctx.raw)}` } } },
    },
  });

  document.getElementById("yearly-start").addEventListener("input", (event) => {
    const start = Math.max(0, parseFloat(event.target.value) || 0);
    plan = yearlyReservePlan(data.totals, start);
    chart.data.datasets[1].data = plan.balances;
    chart.update();
    document.getElementById("yearly-reserve-monthly").textContent = formatter.format(plan.monthly);
  });
}
