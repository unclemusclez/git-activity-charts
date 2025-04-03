document.addEventListener("DOMContentLoaded", function () {
  if (typeof CalHeatmap === "undefined") {
    console.error("CalHeatmap library failed to load.");
    document.getElementById("heatmap").innerHTML =
      "<p>Error: Heatmap library not loaded. Check console.</p>";
    return;
  }

  console.log("Heatmap data loaded:", gitActivityHeatmapData.heatmapData);

  var cal = new CalHeatmap();
  cal.paint(
    {
      data: gitActivityHeatmapData.heatmapData,
      date: {
        start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)),
      },
      range: 12,
      domain: {
        type: "month",
        padding: [0, 10, 0, 10],
        label: { text: "MMM", position: "top" },
      },
      subDomain: { type: "day", width: 12, height: 12, radius: 2 },
      scale: {
        color: {
          range: ["#ebedf0", "#9be9a8", "#40c463", "#30a14e", "#216e39"],
          type: "linear",
          domain: [0, gitActivityHeatmapData.maxValue],
        },
      },
      itemSelector: "#heatmap",
      tooltip: {
        enabled: true,
        text: function (date, value) {
          return (
            value +
            " contribution" +
            (value === 1 ? "" : "s") +
            " on " +
            date.toLocaleDateString()
          );
        },
      },
    },
    [[CalHeatmap.LegendLite, { itemSelector: "#heatmap-legend" }]]
  );
});
