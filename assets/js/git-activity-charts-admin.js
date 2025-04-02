jQuery(document).ready(function ($) {
  // Initialize existing color pickers
  function initColorPicker(element) {
    $(element).wpColorPicker();
  }
  $(".color-picker").each(function () {
    initColorPicker(this);
  });

  // Function to toggle visibility of fields based on provider type
  function toggleProviderFields(accountGroup) {
    const providerType = accountGroup.find(".account-type-select").val();
    const reposField = accountGroup.find(".repos-field");
    const instanceUrlField = accountGroup.find(".instance-url-field");

    // Show/hide Repos field (needed for all except GitHub user contributions)
    if (providerType === "github") {
      reposField.hide();
      reposField.find("input").prop("required", false); // Not required for GitHub user
    } else {
      reposField.show();
      // Optional: make required for others if desired
      reposField.find("input").prop("required", true);
    }

    // Show/hide Instance URL field (needed for GitLab/Gitea self-hosted)
    if (providerType === "gitlab" || providerType === "gitea") {
      instanceUrlField.show();
      // Instance URL isn't strictly *required* if using gitlab.com,
      // but prompt might be useful. Let's not make it formally required.
      // instanceUrlField.find('input').prop('required', true);
    } else {
      instanceUrlField.hide();
      instanceUrlField.find("input").prop("required", false);
    }
    // Update the account header indicator
    accountGroup
      .find(".account-type-indicator")
      .text(providerType.charAt(0).toUpperCase() + providerType.slice(1));
  }

  // Add Account button click handler
  $("#add-account").on("click", function () {
    const accountsContainer = $("#accounts-container");
    const template = $("#account-template").html();
    const index = accountsContainer.find(".account-group").length; // Find next index
    const newAccountHtml = template.replace(/__INDEX__/g, index); // Replace placeholder index

    // Remove "No accounts" message if present
    $("#no-accounts-msg").remove();

    accountsContainer.append(newAccountHtml);

    // Initialize color picker for the new account
    const newGroup = accountsContainer.find(".account-group").last();
    initColorPicker(newGroup.find(".color-picker"));
    toggleProviderFields(newGroup); // Ensure correct fields are shown initially
  });

  // Remove Account button click handler (delegated)
  $("#accounts-container").on("click", ".remove-account", function () {
    $(this).closest(".account-group").remove();
    if ($("#accounts-container .account-group").length === 0) {
      $("#accounts-container").append(
        '<p id="no-accounts-msg">' + "No accounts added yet." + "</p>"
      );
    }
  });

  // Toggle API Key visibility (delegated)
  $("#accounts-container").on("click", ".toggle-api-key", function () {
    const button = $(this);
    const input = button.siblings(".api-key-input");
    if (input.attr("type") === "password") {
      input.attr("type", "text");
      button.text("Hide");
    } else {
      input.attr("type", "password");
      button.text("Show");
    }
  });

  // Handle provider type change to show/hide relevant fields (delegated)
  $("#accounts-container").on("change", ".account-type-select", function () {
    const accountGroup = $(this).closest(".account-group");
    toggleProviderFields(accountGroup);
  });

  // Initial setup for existing accounts on page load
  $(".account-group").each(function () {
    toggleProviderFields($(this));
  });
});
