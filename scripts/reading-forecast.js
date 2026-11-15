// Meter reading input with a forecast from the recent consumption.
// Empty field: the forecast is the placeholder. While typing a prefix of it, the rest shows
// in grey behind the cursor (Tab or → takes it over). Saving an empty field or a prefix
// stores the forecast.

/**
 * Forecast for a reading form, from data attributes set by the page:
 * data-last-value, data-last-date (Y-m-d) and data-per-day (units per day).
 * @returns {() => string|null}
 */
function readingForecastFromData(element, dateInput, meterChangeInput) {
  return () => {
    const perDay = parseFloat(element.dataset.perDay);
    const last = parseFloat(element.dataset.lastValue);
    const lastDate = element.dataset.lastDate;
    if (!(perDay > 0) || Number.isNaN(last) || !lastDate || meterChangeInput?.checked) {
      return null; // no history yet, or a new meter starts from zero
    }
    const date = dateInput?.value || new Date().toISOString().slice(0, 10);
    const days = (new Date(date) - new Date(lastDate)) / 86400000;
    return days > 0 ? String(Math.round(last + perDay * days)) : null;
  };
}

/**
 * Adds the grey completion to a text input. Returns a function that redraws it
 * (call after the date or meter changes). input.readingValue() gives the value to save.
 */
function attachReadingForecast(input, forecastFor) {
  const wrap = document.createElement('span');
  wrap.className = 'ghost-wrap';
  input.parentNode.insertBefore(wrap, input);
  wrap.appendChild(input);
  const ghost = document.createElement('span');
  ghost.className = 'ghost-text';
  ghost.setAttribute('aria-hidden', 'true');
  const typed = document.createElement('span');
  typed.className = 'ghost-typed';
  const rest = document.createElement('span');
  rest.className = 'ghost-rest';
  ghost.append(typed, rest);
  wrap.appendChild(ghost);
  const basePlaceholder = input.placeholder;

  const completion = () => {
    const forecast = forecastFor();
    const text = input.value.trim();
    return forecast !== null && text !== '' && forecast.startsWith(text) && text.length < forecast.length
      ? forecast.slice(text.length) : '';
  };
  const render = () => {
    const forecast = forecastFor();
    input.placeholder = forecast !== null ? forecast : basePlaceholder;
    input.required = forecast === null; // an empty field saves the forecast
    const style = getComputedStyle(input);
    ghost.style.font = style.font;
    ghost.style.letterSpacing = style.letterSpacing;
    ghost.style.paddingLeft = `calc(${style.paddingLeft} + ${style.borderLeftWidth})`;
    typed.textContent = input.value;
    rest.textContent = completion();
    ghost.hidden = rest.textContent === '';
  };

  input.addEventListener('input', render);
  input.addEventListener('keydown', (event) => {
    const atEnd = input.selectionStart === input.value.length;
    if ((event.key === 'Tab' || event.key === 'ArrowRight') && rest.textContent && atEnd) {
      event.preventDefault();
      input.value += rest.textContent;
      render();
    }
  });
  input.readingValue = () => {
    const value = input.value.trim() === '' || completion() ? forecastFor() : input.value;
    return value === null || value === '' ? NaN : parseFloat(String(value).replace(',', '.'));
  };
  render();
  return render;
}
