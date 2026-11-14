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
  const money = apexMoney(data.currency);
  let plan = yearlyReservePlan(data.totals, 0);
  const series = () => [
    { name: translate("yearly_due"), type: "column", data: data.totals },
    { name: translate("yearly_reserve_balance"), type: "line", data: plan.balances.map((v) => Math.round(v * 100) / 100) },
  ];

  const chart = apexRender("yearlyChart", (el, t) => {
    const b = apexBase(el, "line", 360);
    return {
      ...b,
      series: series(),
      colors: [t.main, APEX_GREEN],
      stroke: { curve: "smooth", width: [0, 2] },
      fill: { opacity: [0.75, 1] },
      plotOptions: { bar: { borderRadius: 4, columnWidth: "60%" } },
      markers: { size: [0, 3] },
      xaxis: { ...b.xaxis, categories: data.labels },
      yaxis: { ...b.yaxis, min: 0, labels: { ...b.yaxis.labels, formatter: money } },
      tooltip: { ...b.tooltip, shared: true, intersect: false, y: { formatter: money } },
      legend: { ...b.legend, position: "top" },
    };
  });

  document.getElementById("yearly-start").addEventListener("input", (event) => {
    const start = Math.max(0, parseFloat(event.target.value) || 0);
    plan = yearlyReservePlan(data.totals, start);
    if (chart) chart.updateSeries(series());
    document.getElementById("yearly-reserve-monthly").textContent = money(plan.monthly);
  });
}
