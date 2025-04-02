document.addEventListener("DOMContentLoaded", function () {
  // Check if Chart object exists (script loaded) and if we have data
  if (
    typeof Chart === "undefined" ||
    typeof gitActivityData === "undefined" ||
    !gitActivityData.charts
  ) {
    console.error("Git Activity Charts: Chart.js or chart data not available.");
    // Optionally display a general error message on the page
    const chartsContainer = document.getElementById("git-charts");
    if (chartsContainer) {
      chartsContainer.innerHTML =
        '<p class="error-message">Error loading chart library or data. Please check browser console.</p>';
    }
    return;
  }

  // Keep track of initialized charts
  const initializedCharts = {};

  gitActivityData.charts.forEach((config) => {
    const canvas = document.getElementById(config.canvasId);
    const container = document.getElementById(config.canvasId + "-container"); // Get the container div

    if (!canvas || !container) {
      console.error(
        "Git Activity Charts: Canvas or container not found for ID:",
        config.canvasId
      );
      return;
    }

    const loadingPlaceholder = container.querySelector(".loading-placeholder");
    const errorMessageDiv = container.querySelector(".error-message");

    // Hide loading placeholder initially
    if (loadingPlaceholder) loadingPlaceholder.style.display = "none";

    if (config.error) {
      console.error(
        `Git Activity Charts: Error for ${config.canvasId}: ${config.error}`
      );
      if (errorMessageDiv) {
        errorMessageDiv.textContent = config.error;
        errorMessageDiv.style.display = "block";
      }
      // Hide canvas if there's an error
      canvas.style.display = "none";

      // Special handling for "No data found" - show message instead of technical error
      if (config.nodata && errorMessageDiv) {
        errorMessageDiv.textContent = config.error; // Use the specific no-data message
        errorMessageDiv.classList.add("no-data"); // Add class for styling
        errorMessageDiv.classList.remove("error-message"); // Remove error styling
      }
    } else if (
      config.data &&
      config.data.labels &&
      config.data.labels.length > 0
    ) {
      // Ensure canvas is visible if there was a previous error state cleared by cache refresh
      canvas.style.display = "block";
      if (errorMessageDiv) errorMessageDiv.style.display = "none"; // Hide error div

      // Destroy previous chart instance if it exists (for dynamic updates/refreshes)
      if (initializedCharts[config.canvasId]) {
        initializedCharts[config.canvasId].destroy();
      }

      // Get context and create chart
      const ctx = canvas.getContext("2d");
      try {
        initializedCharts[config.canvasId] = new Chart(ctx, {
          type: config.type || "line", // Default to line chart
          data: config.data,
          options: config.options || {}, // Use provided options
        });
      } catch (e) {
        console.error(
          `Git Activity Charts: Failed to initialize chart for ${config.canvasId}:`,
          e
        );
        if (errorMessageDiv) {
          errorMessageDiv.textContent =
            "Failed to render chart. Check browser console.";
          errorMessageDiv.style.display = "block";
          canvas.style.display = "none";
        }
      }
    } else {
      // Case: No error, but also no data (should be handled by nodata flag, but as fallback)
      console.warn(
        `Git Activity Charts: No data available for ${config.canvasId}, but no specific error reported.`
      );
      if (errorMessageDiv) {
        errorMessageDiv.textContent = "No activity data available to display.";
        errorMessageDiv.style.display = "block";
        errorMessageDiv.classList.add("no-data");
        errorMessageDiv.classList.remove("error-message");
      }
      canvas.style.display = "none";
    }
  });
});
