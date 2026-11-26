
/* Consumption tracker: charts, reading entry, forecast control, CSV import. */

const meterCharts = {};

/* ---------- chart helpers ---------- */

function formatMonthLabel(yyyyMm) {
  const [year, month] = yyyyMm.split('-').map(Number);
  const d = new Date(year, month - 1, 1);
  const monthName = d.toLocaleString(navigator.language, { month: 'short' });
  const shortYear = String(year).slice(-2);
  return `${monthName} '${shortYear}`;
}

function getFilteredMonthly(meterId) {
  const data = window.meterChartData[meterId];
  if (!data) return {};

  const fromInput = document.querySelector(`.chart-from[data-meter="${meterId}"]`);
  const toInput = document.querySelector(`.chart-to[data-meter="${meterId}"]`);

  if (!fromInput?.value || !toInput?.value) {
    return data.monthly;
  }

  const fromMonth = fromInput.value.substring(0, 7);
  const toMonth = toInput.value.substring(0, 7);

  const filtered = {};
  Object.keys(data.monthly).forEach((month) => {
    if (month >= fromMonth && month <= toMonth) {
      filtered[month] = data.monthly[month];
    }
  });
  return filtered;
}

function renderMeterChart(meterId) {
  const data = window.meterChartData[meterId];
  if (!data || !document.getElementById('meterChart-' + meterId)) return;

  const monthly = getFilteredMonthly(meterId);
  const rawLabels = Object.keys(monthly);
  const labels = rawLabels.map(formatMonthLabel);
  const values = rawLabels.map((l) => monthly[l]);
  const axis = apexNumber(1);
  const exact = apexNumber(2);

  if (meterCharts[meterId]) {
    meterCharts[meterId].destroy();
  }

  meterCharts[meterId] = apexRender('meterChart-' + meterId, (el, t) => {
    const b = apexBase(el, 'bar', 300);
    return {
      ...b,
      chart: { ...b.chart, animations: { enabled: values.length <= 24, speed: 300 } },
      series: [{ name: data.unit, data: values }],
      colors: [data.is_pv ? APEX_GREEN : t.main],
      plotOptions: { bar: { borderRadius: 4, columnWidth: '70%' } },
      fill: { opacity: 0.8 },
      xaxis: { ...b.xaxis, categories: labels, tickAmount: Math.min(labels.length, 18), labels: { ...b.xaxis.labels, rotate: -45, hideOverlappingLabels: true } },
      yaxis: {
        ...b.yaxis,
        min: 0,
        labels: { ...b.yaxis.labels, formatter: axis },
        title: { text: data.unit, style: { color: t.text, fontFamily: t.font, fontWeight: 400 } },
      },
      tooltip: { ...b.tooltip, y: { formatter: (v) => exact(v) + ' ' + data.unit } },
      legend: { show: false },
    };
  });
}

function initChartRange(meterId) {
  const data = window.meterChartData[meterId];
  if (!data) return;

  const months = Object.keys(data.monthly);
  const fromInput = document.querySelector(`.chart-from[data-meter="${meterId}"]`);
  const toInput = document.querySelector(`.chart-to[data-meter="${meterId}"]`);
  if (!fromInput || !toInput) return;

  if (months.length > 0) {
    const minMonth = months[0];
    const maxMonth = months[months.length - 1];
    fromInput.value = minMonth + '-01';
    const [y, m] = maxMonth.split('-').map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    toInput.value = `${maxMonth}-${String(lastDay).padStart(2, '0')}`;
  }
}

function setChartPreset(meterId, preset) {
  const data = window.meterChartData[meterId];
  const fromInput = document.querySelector(`.chart-from[data-meter="${meterId}"]`);
  const toInput = document.querySelector(`.chart-to[data-meter="${meterId}"]`);
  if (!data || !fromInput || !toInput) return;

  const months = Object.keys(data.monthly);
  const today = new Date();
  const todayStr = today.toISOString().slice(0, 10);

  let fromDate, toDate;

  if (preset === 'all') {
    if (months.length === 0) return;
    const minMonth = months[0];
    const maxMonth = months[months.length - 1];
    fromDate = minMonth + '-01';
    const [y, m] = maxMonth.split('-').map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    toDate = `${maxMonth}-${String(lastDay).padStart(2, '0')}`;
  } else if (preset === '12m') {
    const from = new Date(today);
    from.setFullYear(from.getFullYear() - 1);
    fromDate = from.toISOString().slice(0, 10);
    toDate = todayStr;
  } else if (preset === '1m') {
    const from = new Date(today);
    from.setMonth(from.getMonth() - 1);
    fromDate = from.toISOString().slice(0, 10);
    toDate = todayStr;
  } else {
    return;
  }

  fromInput.value = fromDate;
  toInput.value = toDate;

  document.querySelectorAll(`.chart-preset-btn[data-meter="${meterId}"]`).forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.preset === preset);
  });

  renderMeterChart(meterId);
}

/* ---------- meter form ---------- */

function toggleMeterTypeFields() {
  const type = document.querySelector('#meter-type').value;
  const conversionGroup = document.querySelector('#meter-conversion-group');
  const baseFeeGroup = document.querySelector('#meter-base-fee-group');
  const priceLabel = document.querySelector('#meter-price-label');
  const isPv = type === 'pv_feed_in' || type === 'pv_generation';
  conversionGroup.classList.toggle('hide', type !== 'gas');
  baseFeeGroup.classList.toggle('hide', isPv);
  document.querySelector('#meter-advance-group').classList.toggle('hide', isPv);
  document.querySelector('#meter-heating-group').classList.toggle('hide', type !== 'electricity');
  document.querySelector('#meter-pv-group').classList.toggle('hide', type !== 'pv_generation');
  document.querySelector('#meter-switch-group').classList.toggle('hide', isPv);
  document.querySelector('#meter-contract-group').classList.toggle('hide', type === 'pv_generation');
  // Generation has no price of its own: its value comes from the grid price it saves.
  document.querySelector('#meter-price-per-unit').closest('.form-group').classList.toggle('hide', type === 'pv_generation');
  document.querySelector('#meter-price-from-group').classList.toggle('hide', type === 'pv_generation');
  priceLabel.textContent =
    type === 'pv_feed_in' ? translate('feed_in_compensation') : translate('price_per_unit');
}

function resetMeterForm() {
  const form = document.querySelector('#meter-form-data');
  form.reset();
  document.querySelector('#meter-id').value = '';
  document.querySelector('#meter-form-title').textContent = translate('add_meter');
  const deleteButton = document.querySelector('#delete-meter');
  deleteButton.style.display = 'none';
  deleteButton.removeAttribute('onClick');
  document.querySelector('#meter-save-button').disabled = false;
  toggleMeterTypeFields();
}

function addMeter() {
  resetMeterForm();
  document.getElementById('meter-form').classList.add('is-open');
  document.querySelector('body').classList.add('no-scroll');
}

function closeMeterForm() {
  document.getElementById('meter-form').classList.remove('is-open');
  document.querySelector('body').classList.remove('no-scroll');
  resetMeterForm();
}

function openEditMeter(event, id) {
  event.stopPropagation();
  fetch(`endpoints/consumption/get_meter.php?id=${id}`)
    .then((response) => response.json())
    .then((meter) => {
      if (meter.error) {
        showErrorMessage(meter.error);
        return;
      }
      document.querySelector('#meter-form-title').textContent = translate('edit_meter');
      document.querySelector('#meter-id').value = meter.id;
      document.querySelector('#meter-name').value = meter.name;
      document.querySelector('#meter-type').value = meter.meter_type;
      document.querySelector('#meter-number').value = meter.meter_number;
      document.querySelector('#meter-price-per-unit').value = meter.price_per_unit;
      document.querySelector('#meter-base-fee').value = meter.base_fee_monthly;
      document.querySelector('#meter-conversion-factor').value = meter.conversion_factor ?? '';
      document.querySelector('#meter-currency').value = meter.currency_id;
      const haField = document.querySelector('#meter-ha-entity');
      if (haField) haField.value = meter.ha_entity_id || '';
      document.querySelector('#meter-monthly-advance').value = meter.monthly_advance || '';
      document.querySelector('#meter-billing-start').value = meter.billing_start || '';
      document.querySelector('#meter-reminder-days').value = meter.reading_reminder_days || '';
      document.querySelector('#meter-is-heating').checked = Number(meter.is_heating) === 1;
      document.querySelector('#meter-forecast-method').value = meter.forecast_method || 'auto';
      document.querySelector('#meter-contract').value = meter.contract_id || '';
      document.querySelector('#meter-guarantee').value = meter.price_guarantee_until || '';
      document.querySelector('#meter-reference-price').value = Number(meter.reference_price) || '';
      document.querySelector('#meter-investment').value = Number(meter.investment_cost) || '';
      document.querySelector('#meter-commissioning').value = meter.commissioning_date || '';
      document.querySelector('#meter-price-valid-from').value = meter.price_valid_from && meter.price_valid_from !== '0000-01-01' ? meter.price_valid_from : '';
      toggleMeterTypeFields();

      const deleteButton = document.querySelector('#delete-meter');
      deleteButton.style.display = 'block';
      deleteButton.setAttribute('onClick', `deleteMeter(event, ${meter.id})`);

      document.getElementById('meter-form').classList.add('is-open');
      document.querySelector('body').classList.add('no-scroll');
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

function deleteMeter(event, id) {
  event.stopPropagation();
  if (!confirm(translate('confirm_delete_meter'))) return;
  fetch('endpoints/consumption/delete_meter.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify({ id: id }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

function archiveMeter(event, id, archived) {
  event.stopPropagation();
  fetch('endpoints/consumption/archive_meter.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify({ id: id, archived: archived }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

/* ---------- readings ---------- */

function deleteReading(event, id) {
  event.stopPropagation();
  fetch('endpoints/consumption/delete_reading.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify({ id: id }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

function openEditReading(event, id) {
  event.stopPropagation();
  const reading = (window.meterReadingsData || {})[id];
  if (!reading) return;

  document.querySelector('#reading-edit-id').value = id;
  document.querySelector('#reading-edit-date').value = reading.reading_date;
  document.querySelector('#reading-edit-value').value = reading.reading_value;
  document.querySelector('#reading-edit-unit').textContent = reading.unit || '';
  document.querySelector('#reading-edit-meter-change').checked = reading.is_meter_change === 1;
  document.querySelector('#reading-edit-note').value = reading.note || '';

  document.getElementById('reading-edit-form').classList.add('is-open');
  document.querySelector('body').classList.add('no-scroll');
}

function closeEditReadingForm() {
  document.getElementById('reading-edit-form').classList.remove('is-open');
  document.querySelector('body').classList.remove('no-scroll');
}

/* ---------- forecast period ---------- */

function updateForecast(meterId, targetDate) {
  fetch(`endpoints/consumption/get_chart_data.php?id=${meterId}&target_date=${targetDate}`)
    .then((r) => r.json())
    .then((data) => {
      const container = document.getElementById('forecast-content-' + meterId);
      if (!container) return;
      if (data.error || data.forecast === null) {
        container.innerHTML = `<span class="forecast-label">${translate('not_enough_data_forecast')}</span>`;
        return;
      }
      const isPv = window.meterChartData[meterId]?.is_pv;
      const nf = new Intl.NumberFormat(navigator.language, { maximumFractionDigits: 0 });
      const unitDisplay = data.unit === 'm3' ? 'm³' : data.unit;
      let html =
        `<span class="forecast-units">+${nf.format(data.forecast.projected_units)} ${unitDisplay}</span>` +
        (data.conversion_factor ? `<span class="forecast-units-secondary">+${nf.format(data.forecast.projected_units * data.conversion_factor)} kWh</span>` : '') +
        `<span class="forecast-label">${translate('projected_consumption_period')}</span>`;
      if (data.forecast_cost !== null) {
        const cf = new Intl.NumberFormat(navigator.language, {
          style: 'currency',
          currency: window.mainCurrencyCode || 'EUR',
        });
        html +=
          `<span class="forecast-cost ${isPv ? 'positive' : ''}">${cf.format(data.forecast_cost)}</span>` +
          `<span class="forecast-label">${translate(isPv ? 'projected_earnings' : 'projected_cost')}</span>`;
      }
      container.innerHTML = html;
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

/* ---------- CSV import ---------- */

let csvImportMeterId = null;
let csvRows = [];
let csvDelimiter = ';';

function openCsvImport(meterId) {
  csvImportMeterId = meterId;
  csvRows = [];
  document.querySelector('#csv-file').value = '';
  document.querySelector('#csv-mapping').classList.add('hide');
  document.querySelector('#csv-preview-table').innerHTML = '';
  document.querySelector('#csv-import-summary').textContent = '';
  document.getElementById('csv-import-form').classList.add('is-open');
  document.querySelector('body').classList.add('no-scroll');
}

function closeCsvImport() {
  document.getElementById('csv-import-form').classList.remove('is-open');
  document.querySelector('body').classList.remove('no-scroll');
  csvImportMeterId = null;
}

function detectDelimiter(line) {
  const candidates = [';', '\t', ','];
  let best = ';';
  let bestCount = 0;
  candidates.forEach((d) => {
    const count = line.split(d).length - 1;
    if (count > bestCount) {
      bestCount = count;
      best = d;
    }
  });
  return best;
}

function splitCsvLine(line, delimiter) {
  const result = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    if (char === '"') {
      inQuotes = !inQuotes;
    } else if (char === delimiter && !inQuotes) {
      result.push(current.trim());
      current = '';
    } else {
      current += char;
    }
  }
  result.push(current.trim());
  return result;
}

function parseGermanDate(value) {
  value = value.trim();
  let match = value.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (match) return `${match[1]}-${match[2]}-${match[3]}`;
  match = value.match(/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})/);
  if (match) {
    let year = match[3];
    if (year.length === 2) year = (parseInt(year, 10) > 70 ? '19' : '20') + year;
    return `${year}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}`;
  }
  return null;
}

function parseGermanNumber(value) {
  value = value.trim().replace(/\s/g, '');
  if (value === '') return null;
  if (value.includes(',')) value = value.replace(/\./g, '').replace(',', '.');
  // "2.657.232" (dots as thousands and decimal mark, e.g. from Home Assistant): the last dot is the
  // decimal one. parseFloat would stop at the second dot and read 2.657.
  else if ((value.match(/\./g) || []).length > 1) value = value.replace(/\.(?=.*\.)/g, '');
  const num = parseFloat(value);
  return isNaN(num) ? null : num;
}

function renderCsvPreview() {
  const table = document.querySelector('#csv-preview-table');
  const hasHeader = document.querySelector('#csv-has-header').checked;
  table.innerHTML = '';
  if (csvRows.length === 0) return;

  const columnCount = csvRows[0].length;
  const dataRows = hasHeader ? csvRows.slice(1) : csvRows;

  const mappingRow = document.createElement('tr');
  for (let c = 0; c < columnCount; c++) {
    const td = document.createElement('td');
    const select = document.createElement('select');
    select.className = 'csv-column-mapping';
    select.dataset.column = c;
    [
      { value: 'ignore', label: translate('csv_ignore_column') },
      { value: 'date', label: translate('date') },
      { value: 'value', label: translate('meter_reading') },
    ].forEach((opt) => {
      const option = document.createElement('option');
      option.value = opt.value;
      option.textContent = opt.label;
      select.appendChild(option);
    });
    const sample = dataRows[0] ? dataRows[0][c] || '' : '';
    if (parseGermanDate(sample) !== null && !mappingRow.querySelector('select option[value="date"]:checked')) {
      select.value = 'date';
    } else if (parseGermanNumber(sample) !== null) {
      select.value = 'value';
    }
    td.appendChild(select);
    mappingRow.appendChild(td);
  }
  table.appendChild(mappingRow);

  let dateSeen = false;
  let valueSeen = false;
  mappingRow.querySelectorAll('select').forEach((select) => {
    if (select.value === 'date') {
      if (dateSeen) select.value = 'ignore';
      dateSeen = true;
    } else if (select.value === 'value') {
      if (valueSeen) select.value = 'ignore';
      valueSeen = true;
    }
  });

  dataRows.slice(0, 5).forEach((row) => {
    const tr = document.createElement('tr');
    for (let c = 0; c < columnCount; c++) {
      const td = document.createElement('td');
      td.textContent = row[c] || '';
      tr.appendChild(td);
    }
    table.appendChild(tr);
  });

  document.querySelector('#csv-import-summary').textContent =
    dataRows.length + ' ' + translate('csv_rows_found');
}

function submitCsvImport() {
  const hasHeader = document.querySelector('#csv-has-header').checked;
  const dataRows = hasHeader ? csvRows.slice(1) : csvRows;
  let dateColumn = null;
  let valueColumn = null;
  document.querySelectorAll('.csv-column-mapping').forEach((select) => {
    if (select.value === 'date') dateColumn = parseInt(select.dataset.column, 10);
    else if (select.value === 'value') valueColumn = parseInt(select.dataset.column, 10);
  });

  if (dateColumn === null || valueColumn === null) {
    showErrorMessage(translate('csv_select_columns'));
    return;
  }

  const readings = [];
  dataRows.forEach((row) => {
    const date = parseGermanDate(row[dateColumn] || '');
    const value = parseGermanNumber(row[valueColumn] || '');
    if (date !== null && value !== null && value >= 0) {
      readings.push({ date: date, value: value });
    }
  });

  if (readings.length === 0) {
    showErrorMessage(translate('csv_no_valid_rows'));
    return;
  }

  document.querySelector('#csv-import-button').disabled = true;
  fetch('endpoints/consumption/import_readings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify({ meter_id: csvImportMeterId, readings: readings }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
    })
    .catch(() => showErrorMessage(translate('unknown_error')))
    .finally(() => {
      document.querySelector('#csv-import-button').disabled = false;
    });
}

/* ---------- init ---------- */

document.addEventListener('DOMContentLoaded', function () {
  // Initialize chart ranges and render charts.
  Object.keys(window.meterChartData || {}).forEach((meterId) => {
    initChartRange(parseInt(meterId, 10));
    renderMeterChart(parseInt(meterId, 10));
  });

  // Chart preset buttons.
  document.querySelectorAll('.chart-preset-btn').forEach((btn) => {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setChartPreset(parseInt(this.dataset.meter, 10), this.dataset.preset);
    });
  });

  // Chart date range inputs: re-render on change, clear active preset.
  document.querySelectorAll('.chart-from, .chart-to').forEach((input) => {
    input.addEventListener('change', function () {
      const meterId = parseInt(this.dataset.meter, 10);
      document.querySelectorAll(`.chart-preset-btn[data-meter="${meterId}"]`).forEach((btn) => {
        btn.classList.remove('active');
      });
      renderMeterChart(meterId);
    });
  });

  // Meter form submit.
  document.querySelector('#meter-form-data')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const submitButton = document.querySelector('#meter-save-button');
    submitButton.disabled = true;
    fetch(this.action, {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.csrfToken },
      body: new FormData(this),
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate('unknown_error'));
        }
      })
      .catch(() => showErrorMessage(translate('unknown_error')))
      .finally(() => { submitButton.disabled = false; });
  });

  // Reading add forms.
  document.querySelectorAll('.reading-form').forEach((form) => {
    const valueInput = form.querySelector('.reading-value');
    const dateInput = form.querySelector('.reading-date');
    const changeInput = form.querySelector('.reading-meter-change');
    const redraw = attachReadingForecast(valueInput, readingForecastFromData(form, dateInput, changeInput));
    dateInput.addEventListener('change', redraw);
    changeInput.addEventListener('change', redraw);
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const readingValue = valueInput.readingValue();
      if (Number.isNaN(readingValue)) {
        showErrorMessage(translate('reading_value_missing'));
        return;
      }
      fetch('endpoints/consumption/add_reading.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
        body: JSON.stringify({
          meter_id: parseInt(form.dataset.meter, 10),
          reading_date: form.querySelector('.reading-date').value,
          reading_value: readingValue,
          is_meter_change: form.querySelector('.reading-meter-change').checked ? 1 : 0,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) {
            showSuccessMessage(data.message);
            window.location.reload();
          } else {
            showErrorMessage(data.message || translate('unknown_error'));
          }
        })
        .catch(() => showErrorMessage(translate('unknown_error')));
    });
  });

  // Reading edit form submit.
  document.querySelector('#reading-edit-form-data')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const submitButton = document.querySelector('#reading-edit-save');
    submitButton.disabled = true;
    fetch('endpoints/consumption/update_reading.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify({
        id: parseInt(document.querySelector('#reading-edit-id').value, 10),
        reading_date: document.querySelector('#reading-edit-date').value,
        reading_value: parseFloat(document.querySelector('#reading-edit-value').value),
        is_meter_change: document.querySelector('#reading-edit-meter-change').checked ? 1 : 0,
        note: document.querySelector('#reading-edit-note').value,
      }),
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message);
          window.location.reload();
        } else {
          showErrorMessage(data.message || translate('unknown_error'));
        }
      })
      .catch(() => showErrorMessage(translate('unknown_error')))
      .finally(() => { submitButton.disabled = false; });
  });

  // Forecast date inputs.
  document.querySelectorAll('.forecast-date').forEach((input) => {
    input.addEventListener('change', function () {
      updateForecast(parseInt(this.dataset.meter, 10), this.value);
    });
  });

  // CSV file input.
  document.querySelector('#csv-file')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
      const text = e.target.result;
      const lines = text.split(/\r?\n/).filter((line) => line.trim() !== '');
      if (lines.length === 0) {
        showErrorMessage(translate('csv_no_valid_rows'));
        return;
      }
      csvDelimiter = detectDelimiter(lines[0]);
      csvRows = lines.map((line) => splitCsvLine(line, csvDelimiter));
      document.querySelector('#csv-mapping').classList.remove('hide');
      renderCsvPreview();
    };
    reader.readAsText(file, 'UTF-8');
  });
});

function compareTariff(input) {
  const box = input.closest(".tariff-compare");
  const units = parseFloat(box.dataset.units);
  const current = parseFloat(box.dataset.current);
  const price = parseFloat(box.querySelector(".tariff-price").value);
  const fee = parseFloat(box.querySelector(".tariff-fee").value) || 0;
  const bonus = parseFloat(box.querySelector(".tariff-bonus").value) || 0;
  const result = box.querySelector(".tariff-result");
  if (isNaN(price)) {
    result.textContent = "";
    return;
  }
  const fmt = (v) => new Intl.NumberFormat(document.documentElement.lang || "de", { style: "currency", currency: box.dataset.currency }).format(v);
  const offer = units * price + fee * 12 - bonus;
  const diff = current - offer;
  result.innerHTML = `${translate("tariff_new")}: <strong>${fmt(offer)}</strong> · ` +
    (diff >= 0 ? `<span class="positive">${translate("tariff_saving")}: ${fmt(diff)}</span>`
               : `<span class="negative">${translate("tariff_more")}: ${fmt(-diff)}</span>`);
}

// Drag and drop order of the meters (handle: grip icon in the panel header).
document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('meter-list');
  if (!list || typeof Sortable === 'undefined' || !list.querySelector('.meter-drag')) return;
  Sortable.create(list, {
    handle: '.meter-drag',
    draggable: '.meter-panel',
    ghostClass: 'sortable-ghost',
    delay: 300,
    delayOnTouchOnly: true,
    onEnd: () => {
      const ids = [...list.querySelectorAll('.meter-panel')].map((panel) => Number(panel.dataset.id));
      fetch('endpoints/consumption/sort_meters.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
        body: JSON.stringify({ ids }),
      })
        .then((r) => r.json())
        .then((data) => data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message || translate('unknown_error')))
        .catch(() => showErrorMessage(translate('unknown_error')));
    },
  });
});

/* ---------- insights: year comparison, weather, tariffs, bills, household ---------- */

function postConsumption(url, body) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify(body),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
      return data;
    })
    .catch(() => showErrorMessage(translate('unknown_error')));
}

function renderYearChart(meterId) {
  const data = window.meterChartData[meterId];
  const id = 'yearChart-' + meterId;
  if (!data || !data.years || !document.getElementById(id) || meterCharts[id]) return;
  const months = [...Array(12)].map((_, i) => new Date(2000, i, 1).toLocaleString(navigator.language, { month: 'short' }));
  const years = Object.keys(data.years).slice(-4); // the last four years stay readable
  const exact = apexNumber(1);
  meterCharts[id] = apexRender(id, (el, t) => {
    const b = apexBase(el, 'bar', 280);
    return {
      ...b,
      series: years.map((y) => ({ name: y, data: Object.values(data.years[y]) })),
      colors: [t.main, ...APEX_PALETTE].slice(0, years.length).reverse(),
      plotOptions: { bar: { borderRadius: 3, columnWidth: '75%' } },
      xaxis: { ...b.xaxis, categories: months },
      yaxis: { ...b.yaxis, min: 0, labels: { ...b.yaxis.labels, formatter: apexNumber(0) } },
      tooltip: { ...b.tooltip, y: { formatter: (v) => (v === null ? '—' : exact(v) + ' ' + data.unit) } },
      legend: { ...b.legend, position: 'top' },
    };
  });
}

function loadWeather(meterId) {
  const box = document.getElementById('weather-' + meterId);
  if (!box || box.dataset.loaded) return;
  box.dataset.loaded = '1';
  fetch(`endpoints/consumption/weather.php?id=${meterId}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        box.innerHTML = '';
        const p = document.createElement('p');
        p.className = 'forecast-label';
        p.textContent = data.error || translate('unknown_error');
        box.appendChild(p);
        return;
      }
      const fmt = apexNumber(1);
      const rows = Object.entries(data.years).map(([year, y]) => `
        <tr><td>${year}${y.months < 12 ? ` <span class="forecast-label">(${y.months}/12)</span>` : ''}</td>
          <td>${fmt(y.units)} ${data.unit}</td><td>${fmt(y.hdd)}</td>
          <td>${y.per_hdd === null ? '—' : apexNumber(2)(y.per_hdd)}</td>
          <td>${y.adjusted === null ? '—' : '<strong>' + fmt(y.adjusted) + ' ' + data.unit + '</strong>'}</td></tr>`).join('');
      box.innerHTML = `
        <div class="table-scroll"><table class="schedule-table">
          <thead><tr><th>${translate('year')}</th><th>${translate('consumption')}</th><th>${translate('degree_days')}</th>
            <th>${translate('per_degree_day')}</th><th>${translate('weather_adjusted_value')}</th></tr></thead>
          <tbody>${rows}</tbody></table></div>
        <p class="forecast-label weather-hint">${translate('weather_adjusted_hint')}</p>`;
      box.querySelector('.weather-hint').append(` (${data.location}, Open-Meteo)`);
    })
    .catch(() => { box.textContent = translate('unknown_error'); });
}

function formValues(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function addTariff(event, meterId) {
  event.preventDefault();
  postConsumption('endpoints/consumption/tariff.php', { action: 'add', meter_id: meterId, ...formValues(event.target) });
  return false;
}

function deleteTariff(id) {
  if (!confirm(translate('confirm_delete'))) return;
  postConsumption('endpoints/consumption/tariff.php', { action: 'delete', id });
}

function addBill(event, meterId) {
  event.preventDefault();
  postConsumption('endpoints/consumption/bill.php', { action: 'add', meter_id: meterId, ...formValues(event.target) });
  return false;
}

function deleteBill(id) {
  if (!confirm(translate('confirm_delete'))) return;
  postConsumption('endpoints/consumption/bill.php', { action: 'delete', id });
}

function openConsumptionSettings() {
  document.getElementById('consumption-settings-form').classList.add('is-open');
  document.querySelector('body').classList.add('no-scroll');
  document.getElementById('cs-persons').focus();
}

function closeConsumptionSettings() {
  document.getElementById('consumption-settings-form').classList.remove('is-open');
  document.querySelector('body').classList.remove('no-scroll');
}

function saveConsumptionSettings(event) {
  event.preventDefault();
  postConsumption('endpoints/consumption/save_settings.php', formValues(event.target));
  return false;
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.meter-insights').forEach((details) => {
    details.addEventListener('toggle', () => {
      if (!details.open) return;
      const meterId = details.dataset.meter;
      renderYearChart(meterId);
      if (details.dataset.heating === '1') loadWeather(meterId);
    });
  });
});
