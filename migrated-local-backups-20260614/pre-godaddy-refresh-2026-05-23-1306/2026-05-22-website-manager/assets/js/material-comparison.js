(function () {
  const materials = {
    quartz: {
      name: "Quartz",
      durability: "High",
      maintenance: "Low",
      heatResistance: "Moderate",
      appearance: "Consistent color and pattern",
      origin: "Engineered surface",
      bestFor: "Busy kitchens and low-maintenance living",
      notes: "Quartz does not require sealing, but trivets are still recommended."
    },
    granite: {
      name: "Granite",
      durability: "High",
      maintenance: "Moderate",
      heatResistance: "High",
      appearance: "Natural variation and movement",
      origin: "Natural stone",
      bestFor: "Natural stone lovers who want durability",
      notes: "Granite should be sealed periodically."
    },
    marble: {
      name: "Marble",
      durability: "Moderate",
      maintenance: "High",
      heatResistance: "High",
      appearance: "Soft, timeless veining",
      origin: "Natural stone",
      bestFor: "Bathrooms and high-end classic looks",
      notes: "Marble can etch and stain more easily."
    },
    quartzite: {
      name: "Quartzite",
      durability: "High",
      maintenance: "Moderate",
      heatResistance: "High",
      appearance: "Bold natural movement",
      origin: "Natural stone",
      bestFor: "Luxury natural stone looks with durability",
      notes: "Quartzite should be sealed and properly maintained."
    }
  };

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function renderComparison(selectedKeys) {
    const container = document.getElementById("comparison-results");
    if (!container) return;

    if (!selectedKeys || selectedKeys.length < 2) {
      container.innerHTML = '<p class="material-compare-empty">Please choose at least two materials to compare.</p>';
      return;
    }

    const selected = selectedKeys.filter((key) => materials[key]);

    if (selected.length < 2) {
      container.innerHTML = '<p class="material-compare-empty">Please choose valid materials.</p>';
      return;
    }

    let html = '<div class="material-compare-grid">';

    selected.forEach((key) => {
      const item = materials[key];
      html += `
        <div class="material-card">
          <h3>${escapeHtml(item.name)}</h3>
          <p><strong>Durability:</strong> ${escapeHtml(item.durability)}</p>
          <p><strong>Maintenance:</strong> ${escapeHtml(item.maintenance)}</p>
          <p><strong>Heat Resistance:</strong> ${escapeHtml(item.heatResistance)}</p>
          <p><strong>Appearance:</strong> ${escapeHtml(item.appearance)}</p>
          <p><strong>Origin:</strong> ${escapeHtml(item.origin)}</p>
          <p><strong>Best For:</strong> ${escapeHtml(item.bestFor)}</p>
          <p><strong>Notes:</strong> ${escapeHtml(item.notes)}</p>
        </div>
      `;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  function initMaterialComparison() {
    const comparisonTool = document.querySelector("[data-material-comparison]");
    if (!comparisonTool) return;

    const options = Array.from(comparisonTool.querySelectorAll('input[name="materials-compare"]'));
    const resetButton = comparisonTool.querySelector("[data-compare-reset]");

    const update = () => {
      renderComparison(
        options
          .filter((option) => option.checked)
          .map((option) => option.value)
      );
    };

    comparisonTool.addEventListener("change", update);

    if (resetButton) {
      resetButton.addEventListener("click", () => {
        options.forEach((option) => {
          option.checked = option.value === "quartz" || option.value === "granite";
        });
        update();
      });
    }

    update();
  }

  window.OGM_MATERIAL_COMPARISON = {
    materials,
    renderComparison
  };

  document.addEventListener("DOMContentLoaded", initMaterialComparison);
})();
