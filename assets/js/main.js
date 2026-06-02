/* ==============================
   SERVITECH MAIN.JS (DB VERSION)
   - Join Queue -> POST to PHP -> MySQL
   ============================== */

/* ==============================
   Modal utilities
   ============================== */
function anyModalVisible() {
  return Array.from(document.querySelectorAll(".modal-overlay"))
    .some((m) => getComputedStyle(m).display !== "none");
}

function syncBodyScrollLock() {
  const shouldLockPage = anyModalVisible() && !document.body.classList.contains("customer-layout");
  document.body.classList.toggle("modal-open", shouldLockPage);
  document.documentElement.classList.toggle("modal-open", shouldLockPage);
}

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) section.scrollIntoView({ behavior: "smooth" });
}

function servitechBasePath() {
  if (typeof window.SERVITECH_BASE_PATH === "string" && window.SERVITECH_BASE_PATH.trim() !== "") {
    return window.SERVITECH_BASE_PATH.replace(/\/+$/, "");
  }
  const pathname = window.location.pathname || "";
  if (pathname === "/ServiTech" || pathname.startsWith("/ServiTech/")) {
    return "/ServiTech";
  }
  return "";
}

function servitechUrl(path) {
  const cleanPath = path.startsWith("/") ? path : `/${path}`;
  return `${servitechBasePath()}${cleanPath}`;
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  }[char]));
}

function formatServicePrice(price) {
  if (price === null || price === undefined || price === "") return "";
  const numericPrice = Number(price);
  if (!Number.isFinite(numericPrice)) return "";
  return `PHP ${numericPrice.toFixed(2)}`;
}

function formatServicePriceRange(priceRange) {
  const value = String(priceRange || "").trim();
  if (value === "") return "";
  return /^price\s*range\s*:/i.test(value) ? value : `Price Range: ${value}`;
}

function parseServiceMoneyValues(value) {
  return String(value || "")
    .match(/\d+(?:\.\d+)?/g)
    ?.map((item) => Number(item))
    .filter((item) => Number.isFinite(item) && item >= 0)
    .sort((a, b) => a - b) || [];
}

function formatPesoPrice(value) {
  const amount = Number(value);
  if (!Number.isFinite(amount)) return "";
  return `\u20B1${amount.toFixed(2)}`;
}

function initServiceFormPriceCard(selectId, targetId, emptyLabel) {
  const select = document.getElementById(selectId);
  const target = document.getElementById(targetId);
  if (!select || !target) return;

  function updatePriceCard() {
    const option = select.options[select.selectedIndex];
    if (!option || option.disabled || !select.value) {
      target.textContent = emptyLabel;
      return;
    }

    const min = Number(option.dataset.min);
    const max = Number(option.dataset.max);
    if (Number.isFinite(min) && Number.isFinite(max)) {
      target.textContent = `${formatPesoPrice(min)} - ${formatPesoPrice(max)}`;
      return;
    }

    target.textContent = option.dataset.priceRange || "Price to be assessed";
  }

  select.addEventListener("change", updatePriceCard);
  updatePriceCard();
}

document.addEventListener("DOMContentLoaded", () => {
  initServiceFormPriceCard("repairServiceSelect", "repairPriceRange", "Choose a repair service");
  initServiceFormPriceCard("installationTypeSelect", "installationPriceRange", "Choose an installation service");
});

function getXeroxPriceMap() {
  return {
    "Long Bond (8.5 x 13)": Number(window.servitechXeroxPricing?.long) || 5,
    "Short Bond (8.5 x 11)": Number(window.servitechXeroxPricing?.short) || 3,
    A4: Number(window.servitechXeroxPricing?.a4) || 3,
    A3: Number(window.servitechXeroxPricing?.a3) || 5,
  };
}

function getDocumentBlockPrice(description, blockName, option) {
  const blocks = String(description || "").split(/\r?\n\s*\r?\n/);
  const block = blocks.find((item) => item.toLowerCase().includes(blockName.toLowerCase()));
  if (!block) return null;
  const match = block.match(new RegExp(`\\b${option}\\s*(?:/\\s*B&W)?\\s*[-\\u2013\\u2014]?\\s*\\u20B1?\\s*(\\d+(?:\\.\\d+)?)`, "i"));
  return match ? Number(match[1]) : null;
}

function getDocumentPrintingPrices(service) {
  let storedPrices = null;
  if (service?.pricing_json) {
    try {
      storedPrices = typeof service.pricing_json === "string"
        ? JSON.parse(service.pricing_json)
        : service.pricing_json;
    } catch (error) {
      storedPrices = null;
    }
  }

  const rangePrices = parseServiceMoneyValues(service?.price_range);
  const description = String(service?.description || "");
  const defaultPrice = rangePrices[0] ?? Number(service?.price) ?? 5;
  const highPrice = rangePrices[rangePrices.length - 1] ?? Math.max(defaultPrice, 10);

  return {
    longFull: storedPrices?.longFull ?? getDocumentBlockPrice(description, "Long Bond", "Full") ?? highPrice,
    longHalf: storedPrices?.longHalf ?? getDocumentBlockPrice(description, "Long Bond", "Half") ?? defaultPrice,
    shortFull: storedPrices?.shortFull ?? getDocumentBlockPrice(description, "Short Bond", "Full") ?? highPrice,
    shortHalf: storedPrices?.shortHalf ?? getDocumentBlockPrice(description, "Short Bond", "Half") ?? defaultPrice,
    a4Full: storedPrices?.a4Full ?? getDocumentBlockPrice(description, "A4", "Full") ?? highPrice,
    a4Half: storedPrices?.a4Half ?? getDocumentBlockPrice(description, "A4", "Half") ?? defaultPrice,
    a3Full: storedPrices?.a3Full ?? getDocumentBlockPrice(description, "A3", "Full") ?? highPrice,
    a3Half: storedPrices?.a3Half ?? getDocumentBlockPrice(description, "A3", "Half") ?? defaultPrice,
  };
}

function buildDocumentPrintingDetailCards(service) {
  const prices = getDocumentPrintingPrices(service);
  const longFullLine = `Full - ${formatPesoPrice(prices.longFull)}`;
  const longHalfLine = `Half - ${formatPesoPrice(prices.longHalf)}`;
  const shortFullLine = `Full - ${formatPesoPrice(prices.shortFull)}`;
  const shortHalfLine = `Half - ${formatPesoPrice(prices.shortHalf)}`;
  const a4FullLine = `Full - ${formatPesoPrice(prices.a4Full)}`;
  const a4HalfLine = `Half - ${formatPesoPrice(prices.a4Half)}`;
  const a3FullLine = `Full - ${formatPesoPrice(prices.a3Full)}`;
  const a3HalfLine = `Half - ${formatPesoPrice(prices.a3Half)}`;

  return [
    { title: "Long Bond Paper (Colored)", icon: "print", lines: [longFullLine, longHalfLine] },
    { title: "Long Bond Paper (B&W)", icon: "print", lines: [formatPesoPrice(prices.longHalf)] },
    { title: "Short Bond Paper (Colored)", icon: "print", lines: [shortFullLine, shortHalfLine] },
    { title: "Short Bond Paper (B&W)", icon: "print", lines: [formatPesoPrice(prices.shortHalf)] },
    { title: "A4 (Colored)", icon: "print", lines: [a4FullLine, a4HalfLine] },
    { title: "A4 (B&W)", icon: "print", lines: [formatPesoPrice(prices.a4Half)] },
    { title: "A3 (Colored)", icon: "print", lines: [a3FullLine, a3HalfLine] },
    { title: "A3 (B&W)", icon: "print", lines: [formatPesoPrice(prices.a3Half)] },
  ];
}

function getRushIdPackagePrices(service) {
  let storedPrices = null;
  if (service?.pricing_json) {
    try {
      storedPrices = typeof service.pricing_json === "string"
        ? JSON.parse(service.pricing_json)
        : service.pricing_json;
    } catch (error) {
      storedPrices = null;
    }
  }

  return {
    package1: storedPrices?.package1 ?? 40,
    package2: storedPrices?.package2 ?? 30,
    package3: storedPrices?.package3 ?? 30,
    package4: storedPrices?.package4 ?? 50,
    package5: storedPrices?.package5 ?? 30,
    package6: storedPrices?.package6 ?? 50,
  };
}

function buildRushIdDetailCards(service) {
  const prices = getRushIdPackagePrices(service);
  return [
    { title: "Package 1", icon: "id", price: formatPesoPrice(prices.package1), lines: ["1x1 (4pcs)", "2x2 (2pcs)"] },
    { title: "Package 2", icon: "id", price: formatPesoPrice(prices.package2), lines: ["1x1 (6pcs)"] },
    { title: "Package 3", icon: "id", price: formatPesoPrice(prices.package3), lines: ["2x2 (4pcs)"] },
    { title: "Package 4", icon: "id", price: formatPesoPrice(prices.package4), lines: ["2x2 (4pcs)", "1x1 (4pcs)"] },
    { title: "Package 5", icon: "id", price: formatPesoPrice(prices.package5), lines: ["Passport size (4pcs)"] },
    { title: "Package 6", icon: "id", price: formatPesoPrice(prices.package6), lines: ["1x1 (10pcs)"] },
  ];
}

function getLaminatingPrices(service) {
  let storedPrices = null;
  if (service?.pricing_json) {
    try {
      storedPrices = typeof service.pricing_json === "string"
        ? JSON.parse(service.pricing_json)
        : service.pricing_json;
    } catch (error) {
      storedPrices = null;
    }
  }

  return {
    thin: storedPrices?.thin ?? 20,
    thick: storedPrices?.thick ?? 30,
  };
}

function buildLaminatingCardLines(service) {
  const prices = getLaminatingPrices(service);
  return [
    `Manipis / Thin: ${formatPesoPrice(prices.thin)}`,
    `Makapal / Thick: ${formatPesoPrice(prices.thick)}`,
  ];
}

function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  if (typeof window.servitechShowModalLayer === "function") {
    window.servitechShowModalLayer(m);
    return;
  }
  m.style.display = "flex";
  syncBodyScrollLock();
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  if (typeof window.servitechHideModalLayer === "function") {
    window.servitechHideModalLayer(m);
    return;
  }
  m.style.display = "none";
  syncBodyScrollLock();
}

/* ==============================
   Service list modal
   ============================== */
const serviceModalData = {
  printing: {
    category: "printing",
    title: "Printing Service",
    description: "Review printing options, available sizes, packages, and pricing details.",
    cards: [
      {
        title: "Document Printing",
        icon: "print",
        lines: ["Long Bond Paper, Short Bond Paper, A4", "Price varies by paper size and color option."],
        detailKey: "documentPrinting",
      },
      { title: "Xerox", lines: ["Long Bond Paper: ₱5", "Short Bond Paper: ₱3", "A4: ₱3"] },
      {
        title: "Rush ID",
        icon: "id",
        lines: ["Choose between packages 1–6.", "Price varies by selected package."],
        detailKey: "rushId",
      },
      { title: "Laminating", lines: ["Manipis / Thin: ₱20", "Makapal / Thick: ₱30"] },
    ],
  },
  repair: {
    category: "repair",
    title: "Device Repair Service",
    cards: [
      { title: "LCD Replacement", lines: ["For mobile phones and laptops.", "Price range: ₱1200 – ₱5500"] },
      { title: "Battery Replacement", lines: ["For mobile phones and laptops.", "Price range: ₱700 – ₱2500"] },
      { title: "Charging Pin Replacement", lines: ["For mobile phones and laptops.", "Price range: ₱800 – ₱4000"] },
      { title: "Speaker / Mouthpiece Replacement", lines: ["For mobile phones and laptops.", "Price range: ₱700 – ₱1500"] },
      { title: "Power Button Repair", lines: ["For mobile phones and laptops.", "Price range: ₱500 – ₱2000"] },
      { title: "Volume Repair", lines: ["For mobile phones and laptops.", "Price range: ₱1000 – ₱2000"] },
      { title: "Camera Repair", lines: ["For mobile phones and laptops.", "Price range: ₱1500 – ₱5000"] },
    ],
  },
  installation: {
    category: "installation",
    title: "Installation / Software",
    cards: [
      { title: "Reprogram Service", lines: ["Price range: ₱1000 – ₱4000"] },
      { title: "Hang Logo Fix Service", lines: ["Price range: ₱1000 – ₱3500"] },
      { title: "Boot Loop Fix Service", lines: ["Price range: ₱1000 – ₱5000"] },
      { title: "Openline Samsung & iPhone", lines: ["Price range: ₱3500 – ₱6000"] },
      { title: "Bypass Google Account", lines: ["Price range: ₱500 – ₱2000"] },
      { title: "Bypass Password", lines: ["Price range: ₱1000 – ₱3000"] },
    ],
  },
};

let serviceDataLoadPromise = null;

const serviceModalDetailData = {
  documentPrinting: {
    title: "Document Printing",
    cards: [
      { title: "Long Bond Paper (Colored)", lines: ["Full – ₱10.00", "Half – ₱5.00"] },
      { title: "Long Bond Paper (B&W)", lines: ["₱5.00"] },
      { title: "Short Bond Paper (Colored)", lines: ["Full – ₱10.00", "Half – ₱5.00"] },
      { title: "Short Bond Paper (B&W)", lines: ["₱5.00"] },
      { title: "A4 (Colored)", lines: ["Full – ₱10.00", "Half – ₱5.00"] },
      { title: "A4 (B&W)", lines: ["₱5.00"] },
    ],
  },
  rushId: {
    title: "Rush ID Packages",
    cards: [
      { title: "Package 1", lines: ["₱40.00", "1x1 (4pcs), 2x2 (2pcs)"] },
      { title: "Package 2", lines: ["₱30.00", "1x1 (6pcs)"] },
      { title: "Package 3", lines: ["₱30.00", "2x2 (4pcs)"] },
      { title: "Package 4", lines: ["₱50.00", "2x2 (4pcs), 1x1 (4pcs)"] },
      { title: "Package 5", lines: ["₱30.00", "Passport size (4pcs)"] },
      { title: "Package 6", lines: ["₱50.00", "1x1 (10pcs)"] },
    ],
  },
};

function renderServiceModalBody(service) {
  return `
    <div class="service-grid">
      ${service.cards
        .map((card) => {
          const clickable = card.detailKey ? " clickable" : "";
          const extra = card.detailKey ? `<p class="more-details">Click for more details</p>` : "";

          return `
        <div class="detail-card${clickable}" data-detail-key="${card.detailKey || ""}">
          <h4>${card.title}</h4>
          ${card.lines.map((line) => `<p>${line}</p>`).join("")}
          ${extra}
        </div>`;
        })
        .join("")}
    </div>
  `;
}

function renderServiceDetailModalBody(detail) {
  return `
    <div class="service-grid">
      ${detail.cards
        .map(
          (card) => `
        <div class="detail-card">
          <h4>${card.title}</h4>
          ${card.lines.map((line) => `<p>${line}</p>`).join("")}
        </div>`
        )
        .join("")}
    </div>
  `;
}

function bindServiceDetailCards(bodyEl) {
  bodyEl.querySelectorAll(".detail-card.clickable").forEach((card) => {
    const detailKey = card.dataset.detailKey;
    if (!detailKey) return;

    card.addEventListener("click", () => openServiceDetailModal(detailKey));
    card.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        openServiceDetailModal(detailKey);
      }
    });
  });
}

async function openServiceModal(sectionId) {
  if (serviceDataLoadPromise) {
    try {
      await serviceDataLoadPromise;
    } catch (error) {
      console.error("Service data load failed:", error);
    }
  }

  const service = serviceModalData[sectionId];
  if (!service) return;

  const overlay = document.getElementById("service-modal");
  const titleEl = document.getElementById("service-modal-title");
  const bodyEl = document.getElementById("service-modal-body");
  if (!overlay || !titleEl || !bodyEl) return;

  titleEl.textContent = service.title;
  bodyEl.innerHTML = renderServiceModalBody(service);
  bindServiceDetailCards(bodyEl);

  overlay.style.display = "flex";
  syncBodyScrollLock();
  document.addEventListener("keydown", escCloseServiceModal);
}

function openServiceDetailModal(detailKey) {
  const detail = serviceModalDetailData[detailKey];
  if (!detail) return;

  const overlay = document.getElementById("service-detail-modal");
  const titleEl = document.getElementById("service-detail-modal-title");
  const bodyEl = document.getElementById("service-detail-modal-body");
  if (!overlay || !titleEl || !bodyEl) return;

  titleEl.textContent = detail.title;
  bodyEl.innerHTML = renderServiceDetailModalBody(detail);

  overlay.style.display = "flex";
  syncBodyScrollLock();
  document.addEventListener("keydown", escCloseServiceDetailModal);
}

function closeServiceModal() {
  const overlay = document.getElementById("service-modal");
  const bodyEl = document.getElementById("service-modal-body");

  if (overlay) overlay.style.display = "none";
  if (bodyEl) bodyEl.innerHTML = "";

  document.removeEventListener("keydown", escCloseServiceModal);
  syncBodyScrollLock();
}

function closeServiceDetailModal() {
  const overlay = document.getElementById("service-detail-modal");
  const bodyEl = document.getElementById("service-detail-modal-body");

  if (overlay) overlay.style.display = "none";
  if (bodyEl) bodyEl.innerHTML = "";

  document.removeEventListener("keydown", escCloseServiceDetailModal);
  syncBodyScrollLock();
}

function escCloseServiceModal(e) {
  if (e.key === "Escape") closeServiceModal();
}

function escCloseServiceDetailModal(e) {
  if (e.key === "Escape") closeServiceDetailModal();
}

serviceModalData.printing.description = "Review printing options, available sizes, packages, and pricing details.";
serviceModalData.printing.cards = [
  {
    title: "Document Printing",
    icon: "print",
    lines: ["Long Bond Paper, Short Bond Paper, A4", "Price varies by paper size and color option."],
    detailKey: "documentPrinting",
  },
  {
    title: "Rush ID",
    icon: "id",
    lines: ["Choose between packages 1-6.", "Price varies by selected package."],
    detailKey: "rushId",
  },
  {
    title: "Xerox",
    icon: "copy",
    lines: ["Long Bond Paper: PHP 5", "Short Bond Paper: PHP 3", "A4: PHP 3"],
  },
  {
    title: "Laminating",
    icon: "laminate",
    lines: ["Manipis / Thin: PHP 20", "Makapal / Thick: PHP 30"],
  },
];

serviceModalData.repair.description = "Explore common hardware repairs for mobile phones and laptops with estimated price ranges.";
serviceModalData.repair.cards = [
  { title: "LCD Replacement", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 1200 - PHP 5500"] },
  { title: "Battery Replacement", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 700 - PHP 2500"] },
  { title: "Charging Pin Replacement", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 800 - PHP 4000"] },
  { title: "Speaker / Mouthpiece Replacement", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 700 - PHP 1500"] },
  { title: "Power Button Repair", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 500 - PHP 2000"] },
  { title: "Volume Repair", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 1000 - PHP 2000"] },
  { title: "Camera Repair", icon: "repair", lines: ["For mobile phones and laptops.", "Price range: PHP 1500 - PHP 5000"] },
];

serviceModalData.installation.description = "Browse software fixes and setup services for device recovery, unlocking, and account bypass.";
serviceModalData.installation.cards = [
  { title: "Reprogram Service", icon: "install", lines: ["Price range: PHP 1000 - PHP 4000"] },
  { title: "Hang Logo Fix Service", icon: "install", lines: ["Price range: PHP 1000 - PHP 3500"] },
  { title: "Boot Loop Fix Service", icon: "install", lines: ["Price range: PHP 1000 - PHP 5000"] },
  { title: "Openline Samsung & iPhone", icon: "install", lines: ["Price range: PHP 3500 - PHP 6000"] },
  { title: "Bypass Google Account", icon: "install", lines: ["Price range: PHP 500 - PHP 2000"] },
  { title: "Bypass Password", icon: "install", lines: ["Price range: PHP 1000 - PHP 3000"] },
];

serviceModalDetailData.documentPrinting.description = "Review document formats, print styles, and price ranges.";
serviceModalDetailData.documentPrinting.title = "Document Printing Price Ranges";
serviceModalDetailData.documentPrinting.cards = [
  { title: "Long Bond Paper (Colored)", icon: "print", lines: ["Full - \u20B110.00", "Half - \u20B15.00"] },
  { title: "Long Bond Paper (B&W)", icon: "print", lines: ["\u20B15.00"] },
  { title: "Short Bond Paper (Colored)", icon: "print", lines: ["Full - \u20B110.00", "Half - \u20B15.00"] },
  { title: "Short Bond Paper (B&W)", icon: "print", lines: ["\u20B15.00"] },
  { title: "A4 (Colored)", icon: "print", lines: ["Full - \u20B110.00", "Half - \u20B15.00"] },
  { title: "A4 (B&W)", icon: "print", lines: ["\u20B15.00"] },
];

serviceModalDetailData.rushId.description = "Compare the available Rush ID package combinations and included photo sizes.";
serviceModalDetailData.rushId.cards = [
  { title: "Package 1", icon: "id", price: "\u20B140.00", lines: ["1x1 (4pcs)", "2x2 (2pcs)"] },
  { title: "Package 2", icon: "id", price: "\u20B130.00", lines: ["1x1 (6pcs)"] },
  { title: "Package 3", icon: "id", price: "\u20B130.00", lines: ["2x2 (4pcs)"] },
  { title: "Package 4", icon: "id", price: "\u20B150.00", lines: ["2x2 (4pcs)", "1x1 (4pcs)"] },
  { title: "Package 5", icon: "id", price: "\u20B130.00", lines: ["Passport size (4pcs)"] },
  { title: "Package 6", icon: "id", price: "\u20B150.00", lines: ["1x1 (10pcs)"] },
];

function getServiceDetailKey(category, serviceName) {
  if (category !== "printing") return undefined;

  const normalizedName = String(serviceName || "")
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, " ");

  if (normalizedName.includes("document") && normalizedName.includes("printing")) return "documentPrinting";
  if (normalizedName.includes("rush") && normalizedName.includes("id")) return "rushId";
  return undefined;
}

function getServiceIcon(iconKey) {
  const icons = {
    print: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M7 3h10v4H7V3zm-1 6h12a3 3 0 0 1 3 3v4h-3v5H6v-5H3v-4a3 3 0 0 1 3-3zm2 5v3h8v-3H8z"></path>
      </svg>`,
    repair: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.7-3.7a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.7 3.7z"></path>
      </svg>`,
    install: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M12 2 4 6v6c0 5.2 3.4 9 8 10 4.6-1 8-4.8 8-10V6zm0 5 4 4h-3v4h-2v-4H8z"></path>
      </svg>`,
    id: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M3 5h18v14H3zm4 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm4 1h7v2h-7zm0 4h5v2h-5z"></path>
      </svg>`,
    copy: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M8 8h11v13H8zm-3-5h11v3H8a3 3 0 0 0-3 3v8H5z"></path>
      </svg>`,
    laminate: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M5 4h14v4H5zm1 6h12l2 10H4zm4 2v5h4v-5z"></path>
      </svg>`,
    default: `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M12 2 2 7l10 5 10-5zm0 8L2 5v12l10 5 10-5V5z"></path>
      </svg>`,
  };

  return icons[iconKey] || icons.default;
}

function getServiceIconKey(category, serviceName) {
  const normalizedName = String(serviceName || "").toLowerCase();

  if (category === "printing") {
    if (normalizedName.includes("xerox")) return "copy";
    if (normalizedName.includes("rush id")) return "id";
    if (normalizedName.includes("laminat")) return "laminate";
    return "print";
  }

  if (category === "repair") return "repair";
  if (category === "installation") return "install";
  return "default";
}

function getServiceCardFallbackLine(category, serviceName) {
  const normalizedName = String(serviceName || "").toLowerCase();

  if (category === "printing") {
    if (normalizedName.includes("document printing")) return "Print documents using common paper sizes and color options.";
    if (normalizedName.includes("xerox")) return "Photocopy service for common paper sizes.";
    if (normalizedName.includes("rush id")) return "ID photo packages for common size requirements.";
    if (normalizedName.includes("laminat")) return "Protect documents with thin or thick laminating options.";
  }

  if (category === "repair") return "Hardware repair service for supported mobile phones and laptops.";
  if (category === "installation") return "Software setup and device recovery support.";
  return "Service information available upon request.";
}

function isServiceCardPriceLine(line) {
  const value = String(line || "").trim();
  return /(?:^|\s)(?:price|php|\u20b1)\b/i.test(value) || /\u20b1\s*\d/i.test(value);
}

function getServiceCardLines(service, card) {
  const category = service.category || "";
  const lines = (card.lines || []).filter((line) => !isServiceCardPriceLine(line));

  if (lines.length > 0) return lines;
  return [getServiceCardFallbackLine(category, card.title)];
}

/* ==============================
   Load Services Dynamically from Database
   ============================== */
async function loadServicesFromDatabase() {
  const baseUrl = servitechBasePath();
  const categories = ["printing", "repair", "installation"];

  for (const category of categories) {
    try {
      const response = await fetch(`${baseUrl}/api/services_public.php?action=list&category=${category}`, { cache: "no-store" });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      
      const data = await response.json();
      if (!data.ok || !data.services || !Array.isArray(data.services)) {
        console.warn(`No services found for category: ${category}`);
        continue;
      }

      // Convert API response to serviceModalData format
      const categoryData = serviceModalData[category] || { title: "", cards: [] };
      categoryData.category = category;
      categoryData.cards = data.services.map((service) => {
        const lines = (service.description || "")
          .split("\n")
          .map((line) => line.trim())
          .filter((line) => line.length > 0);

        const detailKey = getServiceDetailKey(category, service.name);
        if (detailKey === "documentPrinting") {
          serviceModalDetailData.documentPrinting.cards = buildDocumentPrintingDetailCards(service);
        } else if (detailKey === "rushId") {
          serviceModalDetailData.rushId.cards = buildRushIdDetailCards(service);
        }

        const serviceName = String(service.name || "").toLowerCase();
        if (category === "printing" && serviceName.includes("laminat")) {
          lines.splice(0, lines.length, ...buildLaminatingCardLines(service));
        }

        return {
          title: service.name,
          icon: getServiceIconKey(category, service.name),
          lines: lines.length > 0 ? lines : [service.description || ""],
          priceLabel: formatServicePrice(service.price),
          priceRange: service.price_range || "",
          detailKey,
        };
      });

      // Update the serviceModalData
      if (!serviceModalData[category]) {
        serviceModalData[category] = categoryData;
      } else {
        serviceModalData[category].cards = categoryData.cards;
      }
    } catch (error) {
      console.error(`Error loading services for category ${category}:`, error);
      // Fallback to hardcoded data already set in serviceModalData
    }
  }
}

function parseDescriptionBlocks(description) {
  const blocks = description
    .split(/\r?\n\s*\r?\n/)
    .map((block) => block.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))
    .filter((block) => block.length > 0);

  return blocks.map((block) => {
    if (block.length === 1) {
      return { title: block[0], lines: [] };
    }
    return {
      title: block[0],
      lines: block.slice(1),
    };
  });
}

async function fetchServiceDetail(category, serviceName) {
  try {
    const url = `${servitechUrl(`/api/services_public.php`)}?action=detail&category=${encodeURIComponent(category)}&service=${encodeURIComponent(serviceName)}`;
    const response = await fetch(url);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const data = await response.json();
    if (!data.ok || !data.service) {
      throw new Error(data.error || "Service not found");
    }

    return data.service;
  } catch (error) {
    console.error("Error fetching service detail:", error);
    return null;
  }
}

function renderServiceModalBody(service) {
  return `
    <div class="service-grid">
      ${service.cards
        .map((card) => {
          const clickable = card.detailKey ? " clickable" : "";
          const attrs = card.detailKey
            ? ` role="button" tabindex="0" aria-label="Open ${escapeHtml(card.title)} details"`
            : "";
          const lines = getServiceCardLines(service, card);
          const priceRange = formatServicePriceRange(card.priceRange);
          const priceRangeMarkup = priceRange
            ? `<p class="service-price-range">${escapeHtml(priceRange)}</p>`
            : "";
          const cta = card.detailKey
            ? `<div class="service-option-card__bottom"><span class="service-option-card__cta">View details</span></div>`
            : "";
          const iconClass = service.category === "repair"
            ? "service-option-card__icon service-card-icon service-card-icon--repair"
            : "service-option-card__icon";

          return `
        <div class="detail-card service-option-card${clickable}" data-detail-key="${card.detailKey || ""}"${attrs}>
          <div class="service-option-card__top">
            <div class="${iconClass}">${getServiceIcon(card.icon)}</div>
          </div>
          <div class="service-option-card__content">
            <h4>${escapeHtml(card.title)}</h4>
            ${lines.map((line) => `<p>${escapeHtml(line)}</p>`).join("")}
            ${priceRangeMarkup}
          </div>
          ${cta}
        </div>`;
        })
        .join("")}
    </div>
  `;
}

function renderServiceDetailModalBody(detail) {
  const price = detail.priceLabel
    ? `<div class="service-detail-price"><span>Price</span><strong>${escapeHtml(detail.priceLabel)}</strong></div>`
    : "";

  return `
    ${price}
    <div class="service-grid service-detail-grid">
      ${detail.cards
        .map(
          (card) => `
        <div class="detail-card service-option-card service-detail-card">
          <div class="service-option-card__top">
            <div class="service-option-card__icon">${getServiceIcon(card.icon)}</div>
          </div>
          <div class="service-option-card__content">
            <div class="service-detail-card__heading">
              <h4>${escapeHtml(card.title)}</h4>
              ${card.price ? `<strong>${escapeHtml(card.price)}</strong>` : ""}
            </div>
            <div class="service-detail-card__lines">
              ${card.lines.map((line) => `<p>${escapeHtml(line)}</p>`).join("")}
            </div>
          </div>
        </div>`
        )
        .join("")}
    </div>
  `;
}

async function openServiceModal(sectionId) {
  if (serviceDataLoadPromise) {
    try {
      await serviceDataLoadPromise;
    } catch (error) {
      console.error("Service data load failed:", error);
    }
  }

  const service = serviceModalData[sectionId];
  if (!service) return;

  const overlay = document.getElementById("service-modal");
  const titleEl = document.getElementById("service-modal-title");
  const descriptionEl = document.getElementById("service-modal-description");
  const bodyEl = document.getElementById("service-modal-body");
  if (!overlay || !titleEl || !bodyEl) return;

  titleEl.textContent = service.title;
  if (descriptionEl) descriptionEl.textContent = service.description || "Browse the available service options.";
  bodyEl.innerHTML = renderServiceModalBody(service);
  bindServiceDetailCards(bodyEl);

  if (typeof window.servitechShowModalLayer === "function") {
    window.servitechShowModalLayer(overlay);
  } else {
    overlay.style.display = "flex";
  }
  overlay.setAttribute("aria-hidden", "false");
  syncBodyScrollLock();
  document.addEventListener("keydown", escCloseServiceModal);
}

async function openServiceDetailModal(detailKey) {
  const overlay = document.getElementById("service-detail-modal");
  const titleEl = document.getElementById("service-detail-modal-title");
  const descriptionEl = document.getElementById("service-detail-modal-description");
  const bodyEl = document.getElementById("service-detail-modal-body");
  if (!overlay || !titleEl || !bodyEl) return;

  const detail = serviceModalDetailData[detailKey];
  if (!detail) return;

  titleEl.textContent = detail.title;
  if (descriptionEl) descriptionEl.textContent = detail.description || "Review the available service details.";
  bodyEl.innerHTML = renderServiceDetailModalBody(detail);

  if (typeof window.servitechShowModalLayer === "function") {
    window.servitechShowModalLayer(overlay);
  } else {
    overlay.style.display = "flex";
  }
  overlay.setAttribute("aria-hidden", "false");
  syncBodyScrollLock();
  document.addEventListener("keydown", escCloseServiceDetailModal);
}

function closeServiceModal() {
  const overlay = document.getElementById("service-modal");
  const bodyEl = document.getElementById("service-modal-body");

  if (overlay) {
    if (typeof window.servitechHideModalLayer === "function") {
      window.servitechHideModalLayer(overlay);
    } else {
      overlay.style.display = "none";
    }
    overlay.setAttribute("aria-hidden", "true");
  }
  if (bodyEl) bodyEl.innerHTML = "";

  closeServiceDetailModal();
  document.removeEventListener("keydown", escCloseServiceModal);
  syncBodyScrollLock();
}

function closeServiceDetailModal() {
  const overlay = document.getElementById("service-detail-modal");
  const bodyEl = document.getElementById("service-detail-modal-body");

  if (overlay) {
    if (typeof window.servitechHideModalLayer === "function") {
      window.servitechHideModalLayer(overlay);
    } else {
      overlay.style.display = "none";
    }
    overlay.setAttribute("aria-hidden", "true");
  }
  if (bodyEl) bodyEl.innerHTML = "";

  document.removeEventListener("keydown", escCloseServiceDetailModal);
  syncBodyScrollLock();
}

function handleServiceCardKeydown(event, sectionId) {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    openServiceModal(sectionId);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  // Load services from database (with fallback to hardcoded data)
  serviceDataLoadPromise = loadServicesFromDatabase();

  document.querySelectorAll(".service-type-card[data-service-modal]").forEach((card) => {
    const sectionId = card.dataset.serviceModal;
    if (!sectionId) return;

    card.addEventListener("click", () => openServiceModal(sectionId));
    card.addEventListener("keydown", (event) => handleServiceCardKeydown(event, sectionId));
  });
});

/* ==============================
   Generic modal close (outside click)
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".modal-overlay").forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target !== modal) return;

      if (modal.id === "service-modal") {
        closeServiceModal();
        return;
      }

      if (modal.id === "service-detail-modal") {
        closeServiceDetailModal();
        return;
      }

      if (modal.id === "queueModal" && typeof window.closeQueueSuccessModal === "function") {
        window.closeQueueSuccessModal();
        return;
      }

      if (typeof window.servitechHideModalLayer === "function") {
        window.servitechHideModalLayer(modal);
        return;
      }

      modal.style.display = "none";
      syncBodyScrollLock();
    });
  });
});

/* ==============================
   Summary updates
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  const qtyInput = document.getElementById("qtyInput");
  if (!qtyInput) return;

  const paperSizeSelect = document.getElementById("paperSizeSelect");
  const lamTypeSelect = document.getElementById("lamTypeSelect");
  const packageSelect = document.getElementById("packageSelect");
  const colorRadios = document.querySelectorAll('input[name="color"]');

  const summaryPaperSize = document.getElementById("summaryPaperSize");
  const summaryPackage = document.getElementById("summaryPackage");
  const summaryQty = document.getElementById("summaryQty");
  const summaryTotal = document.getElementById("summaryTotal");

  const defaultPrice = 5;
  const svc = document.body?.dataset?.service || "";
  const isXerox = svc === "xerox";

  const xeroxPriceMap = getXeroxPriceMap();

  function updateSummary() {
    const qty = parseInt(qtyInput.value, 10) || 1;
    if (summaryQty) summaryQty.textContent = qty;

    if (paperSizeSelect && summaryPaperSize) {
      const size = paperSizeSelect.value;
      summaryPaperSize.textContent = size && size !== "Select paper size" ? size : "Not Selected";
    }

    if (packageSelect && summaryPackage) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const label = opt?.textContent || "";
      summaryPackage.textContent = label && label !== "Select a Package" ? label : "Not Selected";
    }

    let pricePerItem = defaultPrice;

    if (lamTypeSelect) {
      const opt = lamTypeSelect.options[lamTypeSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : null;
      pricePerItem = p !== null ? p : defaultPrice;
    } else if (packageSelect) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : 0;
      pricePerItem = p;
    } else if (isXerox && paperSizeSelect) {
      const size = paperSizeSelect.value;
      pricePerItem = xeroxPriceMap[size] ?? 0;
    }

    if (summaryTotal) {
      summaryTotal.textContent = `\u20B1${(qty * pricePerItem).toFixed(2)}`;
    }
  }

  if (paperSizeSelect) paperSizeSelect.addEventListener("change", updateSummary);
  if (lamTypeSelect) lamTypeSelect.addEventListener("change", updateSummary);
  if (packageSelect) packageSelect.addEventListener("change", updateSummary);
  qtyInput.addEventListener("input", updateSummary);
  colorRadios.forEach((r) => r.addEventListener("change", updateSummary));

  updateSummary();
});

/* ==============================
   Join queue flow with validation + submit lock
   ============================== */
document.addEventListener("DOMContentLoaded", () => {
  const joinBtn = document.getElementById("joinQueueBtn");
  const queueModal = document.getElementById("queueModal");
  const modalQueueNo = document.getElementById("modalQueueNo");
  const goHomeBtn = document.getElementById("goHomeBtn");
  const viewQueueBtn = document.getElementById("viewQueueBtn");

  if (!joinBtn || !queueModal || !modalQueueNo) return;

  const refs = {
    paperSizeSelect: document.getElementById("paperSizeSelect"),
    qtyInput: document.getElementById("qtyInput"),
    notesEl:
      document.getElementById("notes") ||
      document.getElementById("repairNotes") ||
      document.getElementById("installationNotes") ||
      null,
    packageSelect: document.getElementById("packageSelect"),
    lamTypeSelect: document.getElementById("lamTypeSelect"),
    repairServiceSelect: document.getElementById("repairServiceSelect"),
    deviceTypeSelect: document.getElementById("deviceTypeSelect"),
    installationTypeSelect: document.getElementById("installationTypeSelect"),
    fileUpload: document.getElementById("fileUpload"),
  };
  const svc = (document.body?.dataset?.service || "").toLowerCase();
  const isXerox = svc === "xerox";
  const xeroxPriceMap = getXeroxPriceMap();

  function setFieldInvalid(el, invalid) {
    if (!el) return;
    el.classList.toggle("is-invalid", !!invalid);
  }

  function setRadioInvalid(name, invalid) {
    const first = document.querySelector(`input[name="${name}"]`);
    const group = first ? first.closest(".radio-group") : null;
    if (group) group.classList.toggle("is-invalid", !!invalid);
  }

  function setFeedback(message, tone) {
    if (!message) return;

    if (typeof window.servitechToast === "function") {
      window.servitechToast(message, { tone: tone || "info" });
      return;
    }
    console.warn(message);
  }

  function clearValidationState() {
    [
      refs.paperSizeSelect,
      refs.qtyInput,
      refs.packageSelect,
      refs.lamTypeSelect,
      refs.repairServiceSelect,
      refs.deviceTypeSelect,
      refs.installationTypeSelect,
      refs.fileUpload,
    ].forEach((el) => setFieldInvalid(el, false));
    setRadioInvalid("color", false);
    setFeedback("", "error");
  }

  function getSelectedColor() {
    const radios = document.querySelectorAll('input[name="color"]');
    let val = "";
    radios.forEach((r) => {
      if (r.checked) val = r.value;
    });
    return val;
  }

  function buildServiceLabel() {
    let serviceLabel = "Service";
    const explicitLabel = (document.body?.dataset?.serviceLabel || "").trim();
    if (explicitLabel) return explicitLabel;

    const title = (document.title || "").toLowerCase();

    if (title.includes("document printing")) serviceLabel = "Document Printing";
    if (title.includes("xerox")) serviceLabel = "Xerox";
    if (title.includes("laminating")) serviceLabel = "Laminating";
    if (title.includes("rush id")) serviceLabel = "Rush ID";

    if (refs.repairServiceSelect) {
      const opt = refs.repairServiceSelect.options[refs.repairServiceSelect.selectedIndex];
      serviceLabel = opt ? opt.textContent : "Repair Service";
    }

    if (refs.installationTypeSelect) {
      const opt = refs.installationTypeSelect.options[refs.installationTypeSelect.selectedIndex];
      serviceLabel = opt ? opt.textContent : "Installation Service";
    }

    return serviceLabel;
  }

  function collectPayload() {
    const printState = window.servitechPrintingState || null;
    const fileList = refs.fileUpload && refs.fileUpload.files
      ? Array.from(refs.fileUpload.files)
      : [];
    const derivedFileNames = fileList.length
      ? fileList.map((f) => f.name)
      : (printState && Array.isArray(printState.files)
          ? printState.files
              .map((f) => (f && f.file_name ? f.file_name : null))
              .filter(Boolean)
          : []);
    const fileName = derivedFileNames.length ? derivedFileNames[0] : null;
    const fileNames = derivedFileNames;

    const payload = {
      category: (document.body?.dataset?.service || "general").toLowerCase(),
      service_label: buildServiceLabel(),
      paper_size: refs.paperSizeSelect ? refs.paperSizeSelect.value : null,
      quantity: refs.qtyInput ? parseInt(refs.qtyInput.value, 10) || 1 : 1,
      color_option: getSelectedColor(),
      package_label: refs.packageSelect
        ? (refs.packageSelect.options[refs.packageSelect.selectedIndex]?.textContent || null)
        : null,
      lamination_type: refs.lamTypeSelect ? refs.lamTypeSelect.value : null,
      device_type: refs.deviceTypeSelect ? refs.deviceTypeSelect.value : null,
      notes: refs.notesEl ? refs.notesEl.value : null,
      file_name: fileName,
      file_names: fileNames.length ? fileNames : null,
      total_files: printState && Number.isFinite(printState.total_files)
        ? Number(printState.total_files)
        : null,
      total_images: printState && Number.isFinite(printState.total_images)
        ? Number(printState.total_images)
        : null,
      total_pages: printState && Number.isFinite(printState.total_pages)
        ? Number(printState.total_pages)
        : null,
      price_per_page: printState && Number.isFinite(printState.price_per_page)
        ? Number(printState.price_per_page)
        : null,
      estimated_total: printState && Number.isFinite(printState.estimated_total)
        ? Number(printState.estimated_total)
        : null,
      file_analysis: printState && Array.isArray(printState.files)
        ? printState.files
        : null,
      uploaded_files: printState && Array.isArray(printState.uploaded_files)
        ? printState.uploaded_files
        : null,
    };

    if (isXerox && refs.paperSizeSelect) {
      const price = xeroxPriceMap[payload.paper_size] ?? 0;
      payload.price_per_page = price;
      payload.estimated_total = price * payload.quantity;
    } else if (refs.lamTypeSelect) {
      const selectedOption = refs.lamTypeSelect.options[refs.lamTypeSelect.selectedIndex];
      const price = selectedOption?.dataset?.price ? Number(selectedOption.dataset.price) : 0;
      payload.price_per_page = price;
      payload.estimated_total = price * payload.quantity;
    } else if (refs.packageSelect) {
      const selectedOption = refs.packageSelect.options[refs.packageSelect.selectedIndex];
      const price = selectedOption?.dataset?.price ? Number(selectedOption.dataset.price) : 0;
      payload.price_per_page = price;
      payload.estimated_total = price * payload.quantity;
    }

    return payload;
  }

  function validatePayload(payload) {
    const errors = [];

    if (!payload.service_label || payload.service_label === "Service") {
      errors.push("Please complete the service selection first.");
    }

    if (refs.paperSizeSelect && (!payload.paper_size || refs.paperSizeSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select paper size.");
      setFieldInvalid(refs.paperSizeSelect, true);
    }

    if (refs.qtyInput && (!Number.isFinite(payload.quantity) || payload.quantity < 1)) {
      errors.push("Quantity must be at least 1.");
      setFieldInvalid(refs.qtyInput, true);
    }

    if (refs.packageSelect && (!refs.packageSelect.value || refs.packageSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select a package.");
      setFieldInvalid(refs.packageSelect, true);
    }

    if (refs.lamTypeSelect && (!refs.lamTypeSelect.value || refs.lamTypeSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select lamination type.");
      setFieldInvalid(refs.lamTypeSelect, true);
    }

    if (refs.repairServiceSelect && (!refs.repairServiceSelect.value || refs.repairServiceSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select repair service.");
      setFieldInvalid(refs.repairServiceSelect, true);
    }

    if (refs.deviceTypeSelect && (!refs.deviceTypeSelect.value || refs.deviceTypeSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select device type.");
      setFieldInvalid(refs.deviceTypeSelect, true);
    }

    if (refs.installationTypeSelect && (!refs.installationTypeSelect.value || refs.installationTypeSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select installation type.");
      setFieldInvalid(refs.installationTypeSelect, true);
    }

    const hasColorOptions = document.querySelectorAll('input[name="color"]').length > 0;
    if (hasColorOptions && !payload.color_option) {
      errors.push("Select a color option.");
      setRadioInvalid("color", true);
    }


    const isPrinting = payload.category === "printing";
    if (isPrinting && refs.fileUpload) {
      const printState = window.servitechPrintingState || null;
      const stateFileCount = printState
        ? (Number(printState.total_files) || (Array.isArray(printState.files) ? printState.files.length : 0))
        : 0;
      const hasFiles = stateFileCount > 0 || !!(refs.fileUpload.files && refs.fileUpload.files.length);

      if (!hasFiles) {
        errors.push("Upload at least one file.");
        setFieldInvalid(refs.fileUpload, true);
      }

      if (printState && printState.error) {
        errors.push(printState.error);
      }

    }

    return errors;
  }

  async function createQueue(payload) {
    const csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";
    const res = await fetch(servitechUrl("/api/queue_create.php"), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrf,
      },
      body: JSON.stringify(payload),
    });

    const raw = await res.text();
    try {
      return JSON.parse(raw);
    } catch (e) {
      return {
        ok: false,
        error:
          "Server returned non-JSON. It may have redirected to login (session issue) or PHP error.",
      };
    }
  }

  async function cleanupUploadedFiles(uploadedFiles) {
    if (!Array.isArray(uploadedFiles) || uploadedFiles.length === 0) return;

    const csrf = (window.servitechCsrfToken && window.servitechCsrfToken()) || "";

    try {
      await fetch(servitechUrl("/api/upload_cleanup.php"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": csrf,
        },
        body: JSON.stringify({ uploaded_files: uploadedFiles }),
      });
    } catch (err) {
      console.error("upload cleanup failed", err);
    } finally {
      if (typeof window.servitechResetUploadedFiles === "function") {
        window.servitechResetUploadedFiles();
      }
    }
  }

  joinBtn.addEventListener("click", async (e) => {
    e.preventDefault();
    if (joinBtn.disabled) return;

    clearValidationState();
    const payload = collectPayload();
    const errors = validatePayload(payload);

    if (errors.length) {
      setFeedback(errors.join(" "), "error");
      return;
    }

    joinBtn.disabled = true;
    joinBtn.setAttribute("aria-busy", "true");
    setFeedback("Submitting your queue request...", "info");

    try {
      if (typeof window.servitechBeforeQueueSubmit === "function") {
        const preSubmit = await window.servitechBeforeQueueSubmit();
        if (!preSubmit || preSubmit.ok === false) {
          setFeedback((preSubmit && preSubmit.error) ? preSubmit.error : "File upload failed.", "error");
          return;
        }
        if (preSubmit.payload && typeof preSubmit.payload === "object") {
          Object.assign(payload, preSubmit.payload);
        }
      }

      const result = await createQueue(payload);
      if (!result.ok) {
        await cleanupUploadedFiles(payload.uploaded_files);
        setFeedback("Queue not saved: " + (result.error || "Unknown error"), "error");
        return;
      }

      if (typeof window.openQueueSuccessModal === "function") {
        window.openQueueSuccessModal(result.queue_code, { service: buildServiceLabel() });
      } else {
        modalQueueNo.textContent = result.queue_code;
        queueModal.style.display = "flex";
        syncBodyScrollLock();
      }
      setFeedback("", "error");
    } catch (err) {
      console.error(err);
      setFeedback("Network/server error. Please try again.", "error");
    } finally {
      joinBtn.disabled = false;
      joinBtn.removeAttribute("aria-busy");
    }
  });

  if (goHomeBtn) {
    goHomeBtn.addEventListener("click", () => {
      window.location.href = servitechUrl("/pages/customer/customer_dash.php");
    });
  }

  if (viewQueueBtn) {
    viewQueueBtn.addEventListener("click", () => {
      window.location.href = servitechUrl("/pages/customer/custo_service_status.php");
    });
  }
});
