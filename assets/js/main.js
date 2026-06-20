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

function servitechCatalogRules() {
  return Array.isArray(window.servitechCatalogRules) ? window.servitechCatalogRules : [];
}

function selectedOptionValueKey(select) {
  const option = select?.options?.[select.selectedIndex];
  return option && option.dataset ? (option.dataset.valueKey || "") : "";
}

function checkedValueKey(name) {
  const checked = document.querySelector(`input[name="${name}"]:checked`);
  return checked && checked.dataset ? (checked.dataset.valueKey || "") : "";
}

function findCatalogRuleByKeys(keys) {
  const entries = Object.entries(keys).filter(([, value]) => String(value || "") !== "");
  if (!entries.length) return null;
  return servitechCatalogRules().find((rule) => {
    const ruleKeys = rule?.option_value_keys || {};
    return Number(rule?.active) !== 0 && entries.every(([key, value]) => String(ruleKeys[key] || "") === String(value));
  }) || null;
}

function formatCatalogRuleDisplayPrice(rule) {
  if (!rule || rule.price_type === "assessment") return "For assessment";
  const price = Number(rule.price);
  return Number.isFinite(price) ? formatPesoPrice(price) : "For assessment";
}

function buildLaminatingCardLines(service) {
  return catalogRulesFor(service).map((rule) => {
    const label = rule.option_labels?.lamination_type || rule.label || "Lamination option";
    return `${label}: ${formatCatalogRulePrice(rule)}`;
  });
}

function catalogRulesFor(service) {
  return Array.isArray(service?.catalog?.rules) ? service.catalog.rules : [];
}

function formatCatalogRulePrice(rule) {
  if (!rule || rule.price_type === "assessment") return "For assessment";
  return formatPesoPrice(rule.price);
}

function buildCatalogMatrixCards(service, rowGroupKey, colGroupKey) {
  const rows = new Map();
  catalogRulesFor(service).forEach((rule) => {
    const row = rule.option_labels?.[rowGroupKey] || "";
    const col = rule.option_labels?.[colGroupKey] || "";
    if (!row || !col) return;
    if (!rows.has(row)) rows.set(row, []);
    rows.get(row).push(`${col}: ${formatCatalogRulePrice(rule)}`);
  });
  return Array.from(rows.entries()).map(([title, lines]) => ({
    title,
    icon: getServiceIconKey(service.category, service.name),
    lines,
  }));
}

function buildCatalogRuleCards(service, groupKey) {
  return catalogRulesFor(service).map((rule) => ({
    title: rule.option_labels?.[groupKey] || rule.label || "Option",
    icon: getServiceIconKey(service.category, service.name),
    price: formatCatalogRulePrice(rule),
    lines: [rule.description || ""].filter(Boolean),
  }));
}

function buildRepairCatalogCards(service) {
  const rows = new Map();
  catalogRulesFor(service).forEach((rule) => {
    const device = rule.option_labels?.device_type || "Device";
    const repair = rule.option_labels?.repair_type || rule.label || "Repair";
    if (!rows.has(device)) rows.set(device, []);
    rows.get(device).push(`${repair}: ${formatCatalogRulePrice(rule)}`);
  });
  return Array.from(rows.entries()).map(([title, lines]) => ({
    title,
    icon: "repair",
    lines,
  }));
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
    title: "Print Service",
    description: "Review active print options, available sizes, packages, and pricing details.",
    cards: [],
  },
  repair: {
    category: "repair",
    title: "Device Repair Service",
    cards: [],
  },
  installation: {
    category: "installation",
    title: "Installation / Software",
    cards: [],
  },
};

let serviceDataLoadPromise = null;

const serviceModalDetailData = {
  documentPrinting: { title: "Document Print", cards: [] },
  rushId: { title: "Rush ID Packages", cards: [] },
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

function displayServiceName(name) {
  return String(name || "")
    .replace(/\bXerox\b/gi, "Photocopy")
    .replace(/\bDocument\s+Printing\b/gi, "Document Print")
    .replace(/\bPrinting\s+Service\b/gi, "Print Service")
    .replace(/\bPrinting\b/g, "Print");
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

// Service cards and detail cards are populated from /api/services_public.php.
function getServiceDetailKey(category, serviceName) {
  const normalizedName = String(serviceName || "")
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, " ");

  if (category === "printing" && normalizedName.includes("document") && (normalizedName.includes("printing") || normalizedName.includes("print"))) return "documentPrinting";
  if (category === "printing" && (normalizedName.includes("photocopy") || normalizedName.includes("xerox"))) return "photocopy";
  if (category === "printing" && normalizedName.includes("rush") && normalizedName.includes("id")) return "rushId";
  if (category === "printing" && normalizedName.includes("laminat")) return "laminationCatalog";
  if (category === "repair") return "repairCatalog";
  if (category === "installation") return "installationCatalog";
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
    if (normalizedName.includes("xerox") || normalizedName.includes("photocopy")) return "copy";
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
    if (normalizedName.includes("document print")) return "Print documents using common paper sizes and color options.";
    if (normalizedName.includes("xerox") || normalizedName.includes("photocopy")) return "Photocopy service for common paper sizes.";
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
          serviceModalDetailData.documentPrinting.cards = service.catalog
            ? buildCatalogMatrixCards(service, "paper_size", "color_option")
            : [];
        } else if (detailKey === "photocopy") {
          serviceModalDetailData.photocopy = {
            title: "Photocopy Price Table",
            description: "Review photocopy paper size and color pricing.",
            cards: buildCatalogMatrixCards(service, "paper_size", "color_option"),
          };
        } else if (detailKey === "rushId") {
          serviceModalDetailData.rushId.cards = service.catalog
            ? buildCatalogRuleCards(service, "package")
            : [];
        } else if (detailKey === "laminationCatalog") {
          serviceModalDetailData.laminationCatalog = {
            title: "Lamination Types",
            description: "Review active lamination types and pricing.",
            cards: buildCatalogRuleCards(service, "lamination_type"),
          };
        } else if (detailKey === "repairCatalog") {
          serviceModalDetailData.repairCatalog = {
            title: "Repair Services",
            description: "Review repair types by device.",
            cards: buildRepairCatalogCards(service),
          };
        } else if (detailKey === "installationCatalog") {
          serviceModalDetailData.installationCatalog = {
            title: "Installation Services",
            description: "Review installation service types.",
            cards: buildCatalogRuleCards(service, "installation_type"),
          };
        }

        const serviceName = String(service.name || "").toLowerCase();
        if (category === "printing" && serviceName.includes("laminat") && catalogRulesFor(service).length > 0) {
          lines.splice(0, lines.length, ...buildLaminatingCardLines(service));
        }

        return {
          title: displayServiceName(service.name),
          icon: getServiceIconKey(category, service.name),
          lines: lines.length > 0 ? lines : [service.description || ""],
          priceLabel: formatServicePrice(service.price),
          priceRange: service.catalog_price_range || service.price_range || "",
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
      // Keep this category empty if the catalog API cannot be loaded.
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
  // Load services from the database-backed catalog before opening modals.
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
  if (document.getElementById("summaryTotalPages") || window.servitechDocumentPrintPricing) return;

  const paperSizeSelect = document.getElementById("paperSizeSelect");
  const lamTypeSelect = document.getElementById("lamTypeSelect");
  const packageSelect = document.getElementById("packageSelect");
  const repairServiceSelect = document.getElementById("repairServiceSelect");
  const deviceTypeSelect = document.getElementById("deviceTypeSelect");
  const paymentMethodSelect = document.getElementById("paymentMethodSelect");
  const colorRadios = document.querySelectorAll('input[name="color"]');

  const summaryPaperSize = document.getElementById("summaryPaperSize");
  const summaryPackage = document.getElementById("summaryPackage");
  const summaryLamType = document.getElementById("summaryLamType");
  const summaryPayment = document.getElementById("summaryPayment");
  const summaryQty = document.getElementById("summaryQty");
  const summaryTotal = document.getElementById("summaryTotal");

  const defaultPrice = 5;
  const svc = document.body?.dataset?.service || "";
  const isXerox = svc === "xerox";


  function priceLabelForRule(rule) {
    if (!rule || rule.price_type === "assessment") return "For assessment";
    const price = Number(rule.price);
    return Number.isFinite(price) ? formatPesoPrice(price) : "For assessment";
  }

  function populateRepairTypeOptions() {
    if (!deviceTypeSelect || !repairServiceSelect) return;
    const deviceKey = selectedOptionValueKey(deviceTypeSelect);
    repairServiceSelect.innerHTML = '<option value="" selected disabled>Select Repair Service</option>';
    if (!deviceKey) return;
    servitechCatalogRules()
      .filter((rule) => rule?.option_value_keys?.device_type === deviceKey && Number(rule.active) !== 0)
      .forEach((rule) => {
        const option = document.createElement("option");
        const label = rule.option_labels?.repair_type || rule.label || "Repair Service";
        option.value = label;
        option.textContent = `${label} - ${priceLabelForRule(rule)}`;
        option.dataset.ruleId = String(rule.id || 0);
        option.dataset.catalogId = String(window.servitechCatalogServiceId || document.body?.dataset?.catalogServiceId || 0);
        option.dataset.priceRange = priceLabelForRule(rule);
        repairServiceSelect.appendChild(option);
      });
  }

  function getSelectedColor() {
    const checked = document.querySelector('input[name="color"]:checked');
    return checked ? checked.value : "";
  }

  function readQuantityValue() {
    const raw = String(qtyInput.value || "").trim();
    if (raw === "") return NaN;
    return parseInt(raw, 10);
  }

  function updateSummary() {
    const enteredQty = readQuantityValue();
    const qty = Number.isFinite(enteredQty) && enteredQty > 0 ? enteredQty : 0;
    if (summaryQty) summaryQty.textContent = qtyInput.value.trim() === "" ? "0" : String(qty);

    if (paperSizeSelect && summaryPaperSize) {
      const size = paperSizeSelect.value;
      summaryPaperSize.textContent = size && size !== "Select paper size" ? size : "Not Selected";
    }

    if (packageSelect && summaryPackage) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const label = opt?.textContent || "";
      summaryPackage.textContent = packageSelect.value && !opt?.disabled ? label : "Not Selected";
    }

    if (lamTypeSelect && summaryLamType) {
      const opt = lamTypeSelect.options[lamTypeSelect.selectedIndex];
      summaryLamType.textContent = lamTypeSelect.value && !opt?.disabled ? (opt?.textContent || "Not Selected") : "Not Selected";
    }

    if (paymentMethodSelect && summaryPayment) {
      const value = (paymentMethodSelect.value || "").trim().toLowerCase();
      summaryPayment.textContent = value === "gcash" ? "GCash" : (value === "cash" ? "Cash" : "Not Selected");
    }

    let pricePerItem = 0;
    let canCompute = qty > 0;
    let assessmentPrice = false;

    if (lamTypeSelect) {
      const opt = lamTypeSelect.options[lamTypeSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : NaN;
      assessmentPrice = !!lamTypeSelect.value && !opt?.disabled && !Number.isFinite(p);
      canCompute = canCompute && !!lamTypeSelect.value && !opt?.disabled && Number.isFinite(p);
      pricePerItem = canCompute ? p : 0;
    } else if (packageSelect) {
      const opt = packageSelect.options[packageSelect.selectedIndex];
      const p = opt?.dataset?.price ? parseFloat(opt.dataset.price) : NaN;
      assessmentPrice = !!packageSelect.value && !opt?.disabled && !Number.isFinite(p);
      canCompute = canCompute && !!packageSelect.value && !opt?.disabled && Number.isFinite(p);
      pricePerItem = canCompute ? p : 0;
    } else if (isXerox && paperSizeSelect) {
      const size = paperSizeSelect.value;
      const rule = findCatalogRuleByKeys({
        paper_size: selectedOptionValueKey(paperSizeSelect),
        color_option: checkedValueKey("color"),
      });
      const price = rule && rule.price_type !== "assessment" ? Number(rule.price) : NaN;
      canCompute = canCompute && !!size && !paperSizeSelect.selectedOptions[0]?.disabled && Number.isFinite(price);
      pricePerItem = canCompute ? price : 0;
    } else {
      pricePerItem = defaultPrice;
    }

    if (summaryTotal) {
      summaryTotal.textContent = assessmentPrice ? "For assessment" : (canCompute ? `\u20B1${(qty * pricePerItem).toFixed(2)}` : "\u20B10.00");
    }
  }

  if (paperSizeSelect) paperSizeSelect.addEventListener("change", updateSummary);
  if (lamTypeSelect) lamTypeSelect.addEventListener("change", updateSummary);
  if (packageSelect) packageSelect.addEventListener("change", updateSummary);
  if (deviceTypeSelect) deviceTypeSelect.addEventListener("change", () => {
    populateRepairTypeOptions();
    initServiceFormPriceCard("repairServiceSelect", "repairPriceRange", "Choose a repair service");
  });
  if (repairServiceSelect) repairServiceSelect.addEventListener("change", updateSummary);
  if (paymentMethodSelect) paymentMethodSelect.addEventListener("change", updateSummary);
  qtyInput.addEventListener("input", updateSummary);
  colorRadios.forEach((r) => r.addEventListener("change", updateSummary));

  populateRepairTypeOptions();
  updateSummary();
});

document.addEventListener("DOMContentLoaded", () => {
  const deviceTypeSelect = document.getElementById("deviceTypeSelect");
  const repairServiceSelect = document.getElementById("repairServiceSelect");
  const repairPriceRange = document.getElementById("repairPriceRange");
  if (!deviceTypeSelect || !repairServiceSelect) return;

  function populateRepairTypeOptions() {
    const deviceKey = selectedOptionValueKey(deviceTypeSelect);
    repairServiceSelect.innerHTML = '<option value="" selected disabled>Select Repair Service</option>';
    if (repairPriceRange) repairPriceRange.textContent = "Choose a repair service";
    if (!deviceKey) return;

    servitechCatalogRules()
      .filter((rule) => rule?.option_value_keys?.device_type === deviceKey && Number(rule.active) !== 0)
      .forEach((rule) => {
        const option = document.createElement("option");
        const label = rule.option_labels?.repair_type || rule.label || "Repair Service";
        const priceLabel = formatCatalogRuleDisplayPrice(rule);
        option.value = label;
        option.textContent = `${label} - ${priceLabel}`;
        option.dataset.ruleId = String(rule.id || 0);
        option.dataset.catalogId = String(window.servitechCatalogServiceId || document.body?.dataset?.catalogServiceId || 0);
        option.dataset.priceRange = priceLabel;
        repairServiceSelect.appendChild(option);
      });
  }

  deviceTypeSelect.addEventListener("change", populateRepairTypeOptions);
  repairServiceSelect.addEventListener("change", () => {
    const option = repairServiceSelect.options[repairServiceSelect.selectedIndex];
    if (repairPriceRange) repairPriceRange.textContent = option?.dataset?.priceRange || "For assessment";
  });
  populateRepairTypeOptions();
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
    paymentMethodSelect: document.getElementById("paymentMethodSelect"),
    repairServiceSelect: document.getElementById("repairServiceSelect"),
    deviceTypeSelect: document.getElementById("deviceTypeSelect"),
    installationTypeSelect: document.getElementById("installationTypeSelect"),
    fileUpload: document.getElementById("fileUpload"),
  };
  const svc = (document.body?.dataset?.service || "").toLowerCase();
  const isXerox = svc === "xerox";

  function readQuantityValue() {
    if (!refs.qtyInput) return 1;
    const raw = String(refs.qtyInput.value || "").trim();
    if (raw === "") return NaN;
    return parseInt(raw, 10);
  }

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
      refs.paymentMethodSelect,
      refs.repairServiceSelect,
      refs.deviceTypeSelect,
      refs.installationTypeSelect,
      refs.fileUpload,
      refs.notesEl,
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

    if (title.includes("document print")) serviceLabel = "Document Print";
    if (title.includes("xerox") || title.includes("photocopy")) serviceLabel = "Photocopy";
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
      catalog_service_id: Number(document.body?.dataset?.catalogServiceId || 0) || null,
      paper_size: refs.paperSizeSelect ? refs.paperSizeSelect.value : null,
      quantity: readQuantityValue(),
      color_option: getSelectedColor(),
      payment_method: refs.paymentMethodSelect ? (refs.paymentMethodSelect.value || null) : null,
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

    if (refs.repairServiceSelect) {
      const selectedOption = refs.repairServiceSelect.options[refs.repairServiceSelect.selectedIndex];
      payload.catalog_service_id = Number(selectedOption?.dataset?.catalogId || 0) || payload.catalog_service_id;
      payload.catalog_pricing_rule_id = Number(selectedOption?.dataset?.ruleId || 0) || payload.catalog_pricing_rule_id || null;
    }

    if (refs.installationTypeSelect) {
      const selectedOption = refs.installationTypeSelect.options[refs.installationTypeSelect.selectedIndex];
      payload.catalog_service_id = Number(selectedOption?.dataset?.catalogId || 0) || payload.catalog_service_id;
      payload.catalog_pricing_rule_id = Number(selectedOption?.dataset?.ruleId || 0) || payload.catalog_pricing_rule_id || null;
    }

    if (refs.packageSelect) {
      payload.service_option_key = refs.packageSelect.value || null;
      const selectedOption = refs.packageSelect.options[refs.packageSelect.selectedIndex];
      payload.catalog_pricing_rule_id = Number(selectedOption?.dataset?.ruleId || 0) || payload.catalog_pricing_rule_id || null;
    }

    if (refs.lamTypeSelect) {
      payload.service_option_key = refs.lamTypeSelect.value || null;
      const selectedOption = refs.lamTypeSelect.options[refs.lamTypeSelect.selectedIndex];
      payload.catalog_pricing_rule_id = Number(selectedOption?.dataset?.ruleId || 0) || payload.catalog_pricing_rule_id || null;
    }

    if (isXerox && refs.paperSizeSelect) {
      const rule = findCatalogRuleByKeys({
        paper_size: selectedOptionValueKey(refs.paperSizeSelect),
        color_option: checkedValueKey("color"),
      });
      const price = rule && rule.price_type !== "assessment"
        ? Number(rule.price)
        : null;
      payload.catalog_pricing_rule_id = Number(rule?.id || 0) || payload.catalog_pricing_rule_id || null;
      payload.service_option_key = rule?.rule_key || null;
      payload.price_per_page = Number.isFinite(price) ? price : null;
      payload.estimated_total = Number.isFinite(price) ? price * payload.quantity : null;
    } else if (refs.lamTypeSelect) {
      const selectedOption = refs.lamTypeSelect.options[refs.lamTypeSelect.selectedIndex];
      const price = selectedOption?.dataset?.price ? Number(selectedOption.dataset.price) : null;
      payload.price_per_page = Number.isFinite(price) ? price : null;
      payload.estimated_total = Number.isFinite(price) ? price * payload.quantity : null;
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

    if (refs.paymentMethodSelect && (!payload.payment_method || refs.paymentMethodSelect.selectedOptions[0]?.disabled)) {
      errors.push("Select payment method.");
      setFieldInvalid(refs.paymentMethodSelect, true);
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

    const selectedServiceText = (
      refs.repairServiceSelect?.selectedOptions?.[0]?.textContent ||
      refs.installationTypeSelect?.selectedOptions?.[0]?.textContent ||
      ""
    ).trim();
    if (/\bothers?\b/i.test(selectedServiceText) && !String(payload.notes || "").trim()) {
      errors.push("Please describe your request when selecting Others.");
      setFieldInvalid(refs.notesEl, true);
    }

    const hasColorOptions = document.querySelectorAll('input[name="color"]').length > 0;
    if (hasColorOptions && !payload.color_option) {
      errors.push("Select a color option.");
      setRadioInvalid("color", true);
    }

    if (isXerox && refs.paperSizeSelect && payload.paper_size && payload.color_option) {
      const rule = findCatalogRuleByKeys({
        paper_size: selectedOptionValueKey(refs.paperSizeSelect),
        color_option: checkedValueKey("color"),
      });
      if (!rule) {
        errors.push("The selected photocopy combination is currently unavailable.");
        setFieldInvalid(refs.paperSizeSelect, true);
        setRadioInvalid("color", true);
      }
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

      if (window.servitechJoinQueueLeaveGuard) {
        window.servitechJoinQueueLeaveGuard.disarm();
      }
      if (window.servitechJoinQueuePostSuccess) {
        window.servitechJoinQueuePostSuccess.markComplete(result.queue_code);
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
      await cleanupUploadedFiles(payload.uploaded_files);
      setFeedback("Network/server error. Please try again.", "error");
    } finally {
      joinBtn.disabled = joinBtn.dataset.availabilityLocked === "true";
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



