// Logo picker shared by subscriptions, contracts and insurances: file preview, web logo search, SVG to PNG.
// The name to search for comes from the input marked data-logo-name (subscriptions: #name).
function logoNameInput() {
  return document.querySelector("[data-logo-name]") || document.querySelector("#name");
}

function handleFileSelect(event) {
  const fileInput = event.target;
  const logoPreview = document.querySelector('.logo-preview');
  const logoImg = logoPreview.querySelector('img');
  const logoUrl = document.querySelector("#logo-url");
  logoUrl.value = "";

  // SVG becomes PNG right away (the server only takes bitmaps); the subscription submit converts too, harmlessly.
  const picked = fileInput.files && fileInput.files[0];
  if (picked && picked.type === "image/svg+xml" && typeof DataTransfer === "function") {
    convertSvgToPng(picked, (pngFile) => {
      const transfer = new DataTransfer();
      transfer.items.add(pngFile);
      fileInput.files = transfer.files;
      fileInput.dispatchEvent(new Event("change"));
    });
    return;
  }

  if (fileInput.files && fileInput.files[0]) {
    const reader = new FileReader();

    reader.onload = function (e) {
      logoImg.src = e.target.result;
      logoImg.style.display = 'block';
    };

    reader.readAsDataURL(fileInput.files[0]);
  }
}

function setSearchButtonStatus() {

  const nameInput = logoNameInput();
  const hasSearchTerm = nameInput.value.trim().length > 0;
  const logoSearchButton = document.querySelector("#logo-search-button");
  if (hasSearchTerm) {
    logoSearchButton.classList.remove("disabled");
  } else {
    logoSearchButton.classList.add("disabled");
  }

}

function searchLogo() {
  const nameInput = logoNameInput();
  const searchTerm = nameInput.value.trim();
  if (searchTerm === "") {
    nameInput.focus();
    return;
  }

  const logoSearchPopup = document.querySelector("#logo-search-results");
  const logoResults = document.querySelector("#logo-search-images");
  const logoNav = document.querySelector("#logo-search-nav");
  const logoSearchBackdrop = document.querySelector("#logo-search-backdrop");
  logoSearchPopup.classList.add("is-open");
  if (logoSearchBackdrop) {
    logoSearchBackdrop.classList.add("is-open");
  }
  const subscriptionForm = document.querySelector("#logo-search-results")?.closest(".subscription-form");
  if (subscriptionForm) {
    subscriptionForm.classList.add("scroll-locked");
  }

  const queryInput = document.querySelector("#logo-search-query");
  queryInput.value = searchTerm;

  runLogoSearch(searchTerm);
}

function submitLogoSearch() {
  const queryInput = document.querySelector("#logo-search-query");
  const searchTerm = queryInput.value.trim();
  if (searchTerm === "") {
    queryInput.focus();
    return;
  }

  runLogoSearch(searchTerm);
}

const logoSearchQueryInput = document.querySelector("#logo-search-query");
const logoSearchSubmitButton = document.querySelector("#logo-search-submit");

if (logoSearchQueryInput && logoSearchSubmitButton) {
  logoSearchSubmitButton.addEventListener("click", submitLogoSearch);
  logoSearchQueryInput.addEventListener("keydown", function (event) {
    if (event.key === "Enter") {
      event.preventDefault();
      submitLogoSearch();
    }
  });
}

function runLogoSearch(searchTerm) {
  const logoSearchPopup = document.querySelector("#logo-search-results");
  const logoResults = document.querySelector("#logo-search-images");
  const logoNav = document.querySelector("#logo-search-nav");
  const logoSearchTitle = document.querySelector("#logo-search-title");
  if (logoSearchTitle) {
    const baseTitle = logoSearchTitle.dataset.title;
    logoSearchTitle.textContent = `${baseTitle}: ${searchTerm}`;
  }

  // One section per source, queried in parallel and filled as each response lands
  const encodedSearchTerm = encodeURIComponent(searchTerm);
  const sources = [
    { label: 'selfh.st', url: `endpoints/logos/icon_search.php?search=${encodedSearchTerm}&source=selfhst` },
    { label: 'Dashboard Icons', url: `endpoints/logos/icon_search.php?search=${encodedSearchTerm}&source=dashboardicons` },
    { label: 'DuckDuckGo', url: `endpoints/logos/search.php?search=${encodedSearchTerm}&source=duckduckgo` },
    { label: 'Brave', url: `endpoints/logos/search.php?search=${encodedSearchTerm}&source=brave` },
  ];

  // Google requires user-provided API credentials; the section only exists when configured
  if (logoSearchPopup.dataset.googleSearch) {
    sources.unshift({ label: 'Google', url: `endpoints/logos/google_search.php?search=${encodedSearchTerm}` });
  }

  logoResults.innerHTML = "";
  if (logoNav) {
    logoNav.innerHTML = "";
  }

  sources.forEach(source => {
    const section = document.createElement("div");
    section.className = "logo-search-section";

    const title = document.createElement("h4");
    title.textContent = source.label;
    section.appendChild(title);

    const resultsContainer = document.createElement("div");
    resultsContainer.className = "logo-search-section-results";
    section.appendChild(resultsContainer);

    logoResults.appendChild(section);
    showSearchState(resultsContainer, 'loading');

    if (logoNav) {
      const navItem = document.createElement("button");
      navItem.type = "button";
      navItem.className = "logo-search-nav-item";
      navItem.textContent = source.label;
      navItem.onclick = function () {
        const targetTop = section.getBoundingClientRect().top - logoResults.getBoundingClientRect().top + logoResults.scrollTop;
        logoResults.scrollTo({ top: targetTop, behavior: 'smooth' });
      };
      logoNav.appendChild(navItem);
    }

    fetchLogoSearchSource(source.url)
      .then(data => {
        if (data.results && data.results.length > 0) {
          displayImageResults(data.results, resultsContainer);
        } else if (data.error) {
          console.error(source.label, data.error);
          showSearchState(resultsContainer, 'error');
        } else {
          showSearchState(resultsContainer, 'empty');
        }
      })
      .catch(error => {
        console.error(translate('error_fetching_image_results'), source.label, error);
        showSearchState(resultsContainer, 'error');
      });
  });
}

function fetchLogoSearchSource(url, retry = true) {
  return fetch(url, {
    cache: "no-store",
    headers: { "Accept": "application/json" },
  })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      return response.text();
    })
    .then(body => {
      try {
        return JSON.parse(body);
      } catch (error) {
        throw new Error("Invalid JSON response");
      }
    })
    .catch(error => {
      if (retry) {
        // Wait a moment before retrying: sources like Brave rate-limit
        // aggressively, and retrying instantly just repeats the failure.
        return new Promise(resolve => setTimeout(resolve, 600))
          .then(() => fetchLogoSearchSource(url, false));
      }
      throw error;
    });
}

function displayImageResults(imageSources, container) {
  container.innerHTML = "";

  imageSources.forEach(src => {
    const img = document.createElement("img");
    img.src = src.thumbnail || src.image;
    img.onclick = function () {
      const selectedUrl = getSupportedLogoUrl(src);
      if (selectedUrl) {
        selectWebLogo(selectedUrl, img.src);
      }
    };
    img.onerror = function () {
      this.parentNode.removeChild(this);
    };
    container.appendChild(img);
  });
}

function getSupportedLogoUrl(source) {
  if (source.image) {
    try {
      const imagePath = new URL(source.image, window.location.href).pathname.toLowerCase();
      if (/\.(png|jpe?g|gif|webp)$/.test(imagePath)) {
        return source.image;
      }
    } catch (error) {
      // Fall through to the raster thumbnail for malformed source URLs.
    }
  }

  return source.thumbnail || "";
}

function selectWebLogo(url, previewUrl = url) {
  closeLogoSearch();
  const logoPreview = document.querySelector("#form-logo");
  const logoUrl = document.querySelector("#logo-url");
  logoPreview.src = previewUrl;
  logoPreview.style.display = 'block';
  logoUrl.value = url;
}

function closeLogoSearch() {
  const logoSearchPopup = document.querySelector("#logo-search-results");
  logoSearchPopup.classList.remove("is-open");
  const logoSearchBackdrop = document.querySelector("#logo-search-backdrop");
  if (logoSearchBackdrop) {
    logoSearchBackdrop.classList.remove("is-open");
  }
  const subscriptionForm = document.querySelector("#logo-search-results")?.closest(".subscription-form");
  if (subscriptionForm) {
    subscriptionForm.classList.remove("scroll-locked");
  }
  const logoSearchTitle = document.querySelector("#logo-search-title");
  if (logoSearchTitle) {
    logoSearchTitle.textContent = logoSearchTitle.dataset.title;
  }
  const logoResults = document.querySelector("#logo-search-images");
  logoResults.innerHTML = "";
}

function convertSvgToPng(file, callback) {
  const reader = new FileReader();

  reader.onload = function (e) {
    const img = new Image();
    img.src = e.target.result;
    img.onload = function () {
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const pngDataUrl = canvas.toDataURL('image/png');
      const pngFile = dataURLtoFile(pngDataUrl, file.name.replace(".svg", ".png"));
      callback(pngFile);
    };
  };

  reader.readAsDataURL(file);
}

function dataURLtoFile(dataurl, filename) {
  let arr = dataurl.split(','),
    mime = arr[0].match(/:(.*?);/)[1],
    bstr = atob(arr[1]),
    n = bstr.length,
    u8arr = new Uint8Array(n);

  while (n--) {
    u8arr[n] = bstr.charCodeAt(n);
  }

  return new File([u8arr], filename, { type: mime });
}

// Contracts and insurances: show the stored logo (or none) when the form opens.
function setLogoPreview(filename) {
  const logo = document.querySelector("#form-logo");
  logo.src = filename ? "images/uploads/logos/" + filename : "";
  logo.style.display = filename ? "block" : "none";
  document.querySelector("#logo-url").value = "";
  setTimeout(setSearchButtonStatus); // after the form has its name filled in
}

