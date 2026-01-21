(function () {
  function closestRow(el) {
    while (el && el.tagName && el.tagName.toLowerCase() !== "tr") {
      el = el.parentNode;
    }
    return el;
  }

  function setEditing(row, isEditing) {
    var inputs = row.querySelectorAll("input.wiw-client-edit");
    var views = row.querySelectorAll("span.wiw-client-view");

    for (var i = 0; i < inputs.length; i++) {
      inputs[i].style.display = isEditing ? "" : "none";
    }
    for (var j = 0; j < views.length; j++) {
      views[j].style.display = isEditing ? "none" : "";
    }

    var editBtn = row.querySelector("button.wiw-client-edit-btn");
    var saveBtn = row.querySelector("button.wiw-client-save-btn");
    var resetBtn = row.querySelector("button.wiw-client-reset-btn");
    var cancelBtn = row.querySelector("button.wiw-client-cancel-btn");
    var approveBtn = row.querySelector("button.wiw-client-approve-btn");

    if (editBtn) editBtn.style.display = isEditing ? "none" : "";
    if (saveBtn) saveBtn.style.display = isEditing ? "" : "none";
    if (resetBtn) resetBtn.style.display = isEditing ? "" : "none";
    if (cancelBtn) cancelBtn.style.display = isEditing ? "" : "none";

    // Universal: hide Approve during edit mode, restore after.
    if (approveBtn) {
      approveBtn.style.display = isEditing ? "none" : "";
    }
  }

  function updateViewFromInputs(row) {
    // Update the visible spans from current input values (no validation/persistence yet)
    var cellIn = row.querySelector("td.wiw-client-cell-clock-in");
    var cellOut = row.querySelector("td.wiw-client-cell-clock-out");
    var cellBreak = row.querySelector("td.wiw-client-cell-break");
    var cellSched = row.querySelector("td.wiw-client-cell-sched");
    var cellClocked = row.querySelector("td.wiw-client-cell-clocked");

    if (cellIn) {
      var inInput = cellIn.querySelector("input.wiw-client-edit");
      var inView = cellIn.querySelector("span.wiw-client-view");
      if (inInput && inView) inView.textContent = inInput.value;
    }

    if (cellOut) {
      var outInput = cellOut.querySelector("input.wiw-client-edit");
      var outView = cellOut.querySelector("span.wiw-client-view");
      if (outInput && outView) outView.textContent = outInput.value;
    }

    if (cellBreak) {
      var brInput = cellBreak.querySelector("input.wiw-client-edit");
      var brView = cellBreak.querySelector("span.wiw-client-view");
      if (brInput && brView) brView.textContent = brInput.value;
    }

    if (cellSched) {
      var schedInput = cellSched.querySelector("input.wiw-client-edit");
      var schedView = cellSched.querySelector("span.wiw-client-view");
      if (schedInput && schedView) schedView.textContent = schedInput.value;
    }

    if (cellClocked) {
      var clockedInput = cellClocked.querySelector("input.wiw-client-edit");
      var clockedView = cellClocked.querySelector("span.wiw-client-view");
      if (clockedInput && clockedView) clockedView.textContent = clockedInput.value;
    }
  }

  document.addEventListener("click", function (e) {
    var t = e.target;

    // Edit
    if (t && t.classList && t.classList.contains("wiw-client-edit-btn")) {
      e.preventDefault();
      var row = closestRow(t);
      if (!row) return;
      setEditing(row, true);
      return;
    }

    // Cancel
    if (t && t.classList && t.classList.contains("wiw-client-cancel-btn")) {
      e.preventDefault();
      var row2 = closestRow(t);
      if (!row2) return;

      // Restore input values from view text (simple UI reset)
      var cells = row2.querySelectorAll("td");
      for (var i = 0; i < cells.length; i++) {
        var input = cells[i].querySelector("input.wiw-client-edit");
        var view = cells[i].querySelector("span.wiw-client-view");
        if (input && view) input.value = view.textContent;
      }

      setEditing(row2, false);
      return;
    }

    // Reset (UI only)
    if (t && t.classList && t.classList.contains("wiw-client-reset-btn")) {
      e.preventDefault();
      var row3 = closestRow(t);
      if (!row3) return;

      // If you later add "data-orig" attributes, you can restore from them here.
      // For now: same as cancel behavior.
      var cells2 = row3.querySelectorAll("td");
      for (var j = 0; j < cells2.length; j++) {
        var input2 = cells2[j].querySelector("input.wiw-client-edit");
        var view2 = cells2[j].querySelector("span.wiw-client-view");
        if (input2 && view2) input2.value = view2.textContent;
      }

      setEditing(row3, false);
      return;
    }

    // Save (UI only)
    if (t && t.classList && t.classList.contains("wiw-client-save-btn")) {
      e.preventDefault();
      var row4 = closestRow(t);
      if (!row4) return;

      updateViewFromInputs(row4);
      setEditing(row4, false);

      // No persistence yet (intentionally)
      return;
    }
  });
})();
