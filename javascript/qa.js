(function () {
	function onReady(callback) {
		if (document.readyState === "loading") {
			document.addEventListener("DOMContentLoaded", callback);
			return;
		}

		callback();
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	function getStatusIcon(status) {
		if (status === "pass") {
			return "&#9989;";
		}

		if (status === "fail") {
			return "&#10060;";
		}

		if (status === "skipped") {
			return "&#9193;";
		}

		return "&#9888;";
	}

	function getStatusLabel(status) {
		if (status === "pass") {
			return "Pass";
		}

		if (status === "fail") {
			return "Fail";
		}

		if (status === "skipped") {
			return "Skipped";
		}

		return "Error";
	}

	function getSectionSummary(checks) {
		var hasFail = false;
		var hasPass = false;
		var hasOnlySkipped = checks.length > 0;

		checks.forEach(function (check) {
			var status = typeof check.status === "string" ? check.status : "";

			if (status === "fail" || status === "error") {
				hasFail = true;
			}

			if (status === "pass") {
				hasPass = true;
				hasOnlySkipped = false;
				return;
			}

			if (status !== "skipped") {
				hasOnlySkipped = false;
			}
		});

		if (hasFail) {
			return { label: "Needs attention", className: "is-fail" };
		}

		if (hasPass) {
			return { label: "Pass", className: "is-pass" };
		}

		if (hasOnlySkipped) {
			return { label: "Skipped", className: "is-skipped" };
		}

		return { label: "Pass", className: "is-pass" };
	}

	function calculateScore(results) {
		var passed = 0;
		var total = 0;

		results.forEach(function (section) {
			var checks = Array.isArray(section.checks) ? section.checks : [];

			checks.forEach(function (check) {
				var status = typeof check.status === "string" ? check.status : "";

				if (status === "skipped") {
					return;
				}

				total += 1;

				if (status === "pass") {
					passed += 1;
				}
			});
		});

		return { passed: passed, total: total };
	}

	function formatLastRun(timestamp, strings) {
		var numericTimestamp = Number(timestamp || 0);

		if (!numericTimestamp) {
			return strings.lastRunNever || "Last run: Not yet";
		}

		var currentTimestamp = Math.floor(Date.now() / 1000);
		var delta = Math.max(0, currentTimestamp - numericTimestamp);

		if (delta < 60) {
			return "Last run: just now";
		}

		if (delta < 3600) {
			return "Last run: " + Math.floor(delta / 60) + " minute(s) ago";
		}

		if (delta < 86400) {
			return "Last run: " + Math.floor(delta / 3600) + " hour(s) ago";
		}

		return "Last run: " + new Date(numericTimestamp * 1000).toLocaleString();
	}

	function extractErrorMessage(payload, response) {
		if (payload && typeof payload.message === "string" && payload.message) {
			return payload.message;
		}

		return "Request failed with status " + response.status + ".";
	}

	function getTrimmedValue(input) {
		if (!input || typeof input.value !== "string") {
			return "";
		}

		return input.value.trim();
	}

	function getTrimmedLocation(locationInput) {
		return getTrimmedValue(locationInput);
	}

	function debounce(callback, delay) {
		var timeoutId = 0;

		return function () {
			var args = arguments;

			window.clearTimeout(timeoutId);
			timeoutId = window.setTimeout(function () {
				callback.apply(null, args);
			}, delay);
		};
	}

	onReady(function () {
		var data = window.scwriterBlogQaData || {};
		var strings = data.strings || {};
		var root = document.getElementById("blogqa-meta-box");
		var locationInput = document.getElementById("blogqa-location");
		var pillarPostIdInput = document.getElementById("blogqa-pillar-post-id");
		var pillarPostLabelInput = document.getElementById("blogqa-pillar-post-label");
		var pillarPostResults = document.getElementById("blogqa-pillar-post-results");
		var pillarModeNode = document.getElementById("blogqa-pillar-mode");
		var secondaryKeywordsInput = document.getElementById("blogqa-secondary-keywords");
		var runButton = document.getElementById("blogqa-run-button");
		var spinner = document.getElementById("blogqa-spinner");
		var scoreNode = document.getElementById("blogqa-score");
		var lastRunNode = document.getElementById("blogqa-last-run");
		var resultsModeNode = document.getElementById("blogqa-results-mode");
		var resultsNode = document.getElementById("blogqa-results");
		var errorNode = document.getElementById("blogqa-error");

		if (!root || !runButton || !resultsNode || !scoreNode || !lastRunNode) {
			return;
		}

		function renderEmptyState() {
			resultsNode.innerHTML =
				'<p class="blogqa-placeholder">' +
				escapeHtml(strings.runFirst || "Run QA to evaluate this post.") +
				"</p>";
			scoreNode.textContent = strings.scoreEmpty || "No results yet";
		}

		function getCurrentMode() {
			return getTrimmedValue(pillarPostIdInput) ? "regular" : "pillar";
		}

		function syncPillarSelectionState() {
			if (!pillarPostIdInput || !pillarPostLabelInput) {
				return;
			}

			if (!getTrimmedValue(pillarPostLabelInput)) {
				pillarPostIdInput.value = "";
				pillarPostLabelInput.dataset.selectedLabel = "";
			}
		}

		function getModeText(mode, textType) {
			if (textType === "results") {
				return mode === "regular"
					? strings.resultsModeRegular || "Last results: Regular mode"
					: strings.resultsModePillar || "Last results: Pillar mode";
			}

			return mode === "regular"
				? strings.currentModeRegular ||
						"Current selection: regular mode. The selected Pillar Post will be used for cross-post comparisons."
				: strings.currentModePillar ||
						"Current selection: pillar mode. With no selected Pillar Post, this post is evaluated as the pillar post.";
		}

		function updateCurrentModeText() {
			syncPillarSelectionState();

			if (!pillarModeNode) {
				return;
			}

			pillarModeNode.textContent = getModeText(getCurrentMode(), "current");
		}

		function updateResultsModeText(mode, hasResults) {
			if (!resultsModeNode) {
				return;
			}

			if (!hasResults) {
				resultsModeNode.textContent = "";
				return;
			}

			resultsModeNode.textContent = getModeText(mode === "regular" ? "regular" : "pillar", "results");
		}

		function renderResults(results) {
			if (!Array.isArray(results) || !results.length) {
				renderEmptyState();
				updateResultsModeText(getCurrentMode(), false);
				return;
			}

			var html = results
				.map(function (section) {
					var checks = Array.isArray(section.checks) ? section.checks : [];
					var summary = getSectionSummary(checks);
					var checksHtml = checks
						.map(function (check) {
							var status = typeof check.status === "string" ? check.status : "error";
							var reason =
								typeof check.reason === "string" && status !== "pass"
									? check.reason
									: "";

							return (
								'<div class="blogqa-check">' +
								'<div class="blogqa-check-heading">' +
								'<span class="blogqa-check-status ' +
								escapeHtml(status) +
								'">' +
								getStatusIcon(status) +
								" " +
								escapeHtml(getStatusLabel(status)) +
								"</span>" +
								'<span class="blogqa-check-label">' +
								escapeHtml(check.label || "Check") +
								"</span>" +
								"</div>" +
								(reason
									? '<p class="blogqa-check-reason">' + escapeHtml(reason) + "</p>"
									: "") +
								"</div>"
							);
						})
						.join("");

					return (
						'<section class="blogqa-section">' +
						'<div class="blogqa-section-header">' +
						'<h4 class="blogqa-section-title">' +
						escapeHtml(section.label || "Section") +
						"</h4>" +
						'<span class="blogqa-section-badge ' +
						escapeHtml(summary.className) +
						'">' +
						escapeHtml(summary.label) +
						"</span>" +
						"</div>" +
						'<div class="blogqa-section-body">' +
						checksHtml +
						"</div>" +
						"</section>"
					);
				})
				.join("");

			resultsNode.innerHTML = html;

			var score = calculateScore(results);
			scoreNode.textContent = score.passed + " / " + score.total + " passed";
		}

		function setLoading(isLoading) {
			root.classList.toggle("is-loading", isLoading);
			runButton.disabled = isLoading;
			runButton.textContent = isLoading
				? strings.running || "Running QA..."
				: strings.run || "Run QA";

			if (spinner) {
				spinner.classList.toggle("is-active", isLoading);
			}
		}

		function setError(message) {
			if (!errorNode) {
				return;
			}

			errorNode.hidden = false;
			errorNode.textContent = message;
		}

		function clearError() {
			if (!errorNode) {
				return;
			}

			errorNode.hidden = true;
			errorNode.textContent = "";
		}

		function hidePillarResults() {
			if (!pillarPostResults) {
				return;
			}

			pillarPostResults.hidden = true;
			pillarPostResults.innerHTML = "";
		}

		function setPillarSelection(post) {
			if (pillarPostIdInput) {
				pillarPostIdInput.value =
					post && typeof post.id !== "undefined" ? String(post.id) : "";
			}

			if (pillarPostLabelInput) {
				pillarPostLabelInput.value =
					post && typeof post.label === "string" ? post.label : "";
				pillarPostLabelInput.dataset.selectedLabel =
					post && typeof post.label === "string" ? post.label : "";
			}

			hidePillarResults();
			updateCurrentModeText();
		}

		function renderPillarResults(items, state) {
			if (!pillarPostResults) {
				return;
			}

			if (state === "loading") {
				pillarPostResults.innerHTML =
					'<div class="blogqa-autocomplete-status">' +
					escapeHtml(
						strings.pillarSearchLoading || "Searching pillar posts..."
					) +
					"</div>";
				pillarPostResults.hidden = false;
				return;
			}

			if (state === "error") {
				pillarPostResults.innerHTML =
					'<div class="blogqa-autocomplete-status">' +
					escapeHtml(
						strings.pillarSearchError || "Could not load pillar posts."
					) +
					"</div>";
				pillarPostResults.hidden = false;
				return;
			}

			if (!Array.isArray(items) || !items.length) {
				pillarPostResults.innerHTML =
					'<div class="blogqa-autocomplete-status">' +
					escapeHtml(strings.pillarSearchEmpty || "No pillar posts found.") +
					"</div>";
				pillarPostResults.hidden = false;
				return;
			}

			pillarPostResults.innerHTML = items
				.map(function (item) {
					return (
						'<button type="button" class="blogqa-autocomplete-option" data-id="' +
						escapeHtml(item.id) +
						'" data-label="' +
						escapeHtml(item.label || "") +
						'">' +
						escapeHtml(item.label || "") +
						"</button>"
					);
				})
				.join("");
			pillarPostResults.hidden = false;
		}

		var searchPillarPosts = debounce(function (searchTerm) {
			if (!data.pillarSearchUrl || !pillarPostLabelInput) {
				return;
			}

			renderPillarResults([], "loading");

			var searchUrl = new URL(data.pillarSearchUrl, window.location.origin);
			searchUrl.searchParams.set("search", searchTerm);
			searchUrl.searchParams.set("exclude_post_id", String(data.postId || 0));

			fetch(searchUrl.toString(), {
				method: "GET",
				credentials: "same-origin",
				headers: {
					"X-WP-Nonce": data.nonce || "",
				},
			})
				.then(function (response) {
					return response
						.json()
						.catch(function () {
							return null;
						})
						.then(function (payload) {
							if (!response.ok) {
								throw new Error(extractErrorMessage(payload, response));
							}

							return payload;
						});
				})
				.then(function (payload) {
					renderPillarResults(Array.isArray(payload) ? payload : []);
				})
				.catch(function () {
					renderPillarResults([], "error");
				});
		}, 250);

		function handlePillarInput() {
			if (!pillarPostLabelInput) {
				return;
			}

			if (
				pillarPostLabelInput.dataset.selectedLabel &&
				pillarPostLabelInput.dataset.selectedLabel !== pillarPostLabelInput.value &&
				pillarPostIdInput
			) {
				pillarPostIdInput.value = "";
			}

			syncPillarSelectionState();
			updateCurrentModeText();
			searchPillarPosts(getTrimmedValue(pillarPostLabelInput));
		}

		if (locationInput && data.location && !locationInput.value) {
			locationInput.value = String(data.location);
		}

		if (
			pillarPostIdInput &&
			data.pillarPostId &&
			!pillarPostIdInput.value
		) {
			pillarPostIdInput.value = String(data.pillarPostId);
		}

		if (
			pillarPostLabelInput &&
			data.pillarPostLabel &&
			!pillarPostLabelInput.value
		) {
			pillarPostLabelInput.value = String(data.pillarPostLabel);
		}

		if (pillarPostLabelInput) {
			pillarPostLabelInput.dataset.selectedLabel =
				typeof data.pillarPostLabel === "string" ? data.pillarPostLabel : "";
			pillarPostLabelInput.placeholder =
				strings.pillarSearchPlaceholder || "Search pillar posts";
		}

		if (
			secondaryKeywordsInput &&
			typeof data.secondaryKeywords === "string" &&
			!secondaryKeywordsInput.value
		) {
			secondaryKeywordsInput.value = data.secondaryKeywords;
		}

		syncPillarSelectionState();
		updateCurrentModeText();
		renderResults(Array.isArray(data.initialResults) ? data.initialResults : []);
		lastRunNode.textContent = formatLastRun(data.lastRun, strings);
		updateResultsModeText(
			typeof data.lastRunMode === "string" ? data.lastRunMode : getCurrentMode(),
			Array.isArray(data.initialResults) && data.initialResults.length > 0
		);

		if (pillarPostLabelInput) {
			pillarPostLabelInput.addEventListener("input", handlePillarInput);
			pillarPostLabelInput.addEventListener("focus", function () {
				searchPillarPosts(getTrimmedValue(pillarPostLabelInput));
			});
			pillarPostLabelInput.addEventListener("blur", function () {
				window.setTimeout(hidePillarResults, 150);
			});
		}

		if (pillarPostResults) {
			pillarPostResults.addEventListener("click", function (event) {
				var option = event.target.closest(".blogqa-autocomplete-option");

				if (!option) {
					return;
				}

				setPillarSelection({
					id: option.getAttribute("data-id") || "",
					label: option.getAttribute("data-label") || "",
				});
			});
		}

		runButton.addEventListener("click", function () {
			if (!data.restUrl) {
				setError("Missing REST configuration for Spark Ignite Blog QA.");
				return;
			}

			var locationValue = getTrimmedLocation(locationInput);

			if (!locationValue) {
				setError(strings.locationRequired || "Location is required to run QA.");

				if (locationInput) {
					locationInput.focus();
				}

				return;
			}

			clearError();
			setLoading(true);

			var pillarPostIdValue = getTrimmedValue(pillarPostIdInput);
			var secondaryKeywordsValue = getTrimmedValue(secondaryKeywordsInput);

			fetch(data.restUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": data.nonce || "",
				},
				body: JSON.stringify({
					location: locationValue,
					pillar_post_id: pillarPostIdValue,
					secondary_keywords: secondaryKeywordsValue,
				}),
			})
				.then(function (response) {
					return response
						.json()
						.catch(function () {
							return null;
						})
						.then(function (payload) {
							if (!response.ok) {
								throw new Error(extractErrorMessage(payload, response));
							}

							return payload;
						});
				})
				.then(function (payload) {
					var results = Array.isArray(payload && payload.results)
						? payload.results
						: Array.isArray(payload)
							? payload
							: [];
					var lastRun = payload && payload.last_run
						? Number(payload.last_run)
						: Math.floor(Date.now() / 1000);
					var mode =
						payload && typeof payload.mode === "string" && payload.mode
							? payload.mode
							: getCurrentMode();

					renderResults(results);
					lastRunNode.textContent = formatLastRun(lastRun, strings);
					updateResultsModeText(mode, results.length > 0);
				})
				.catch(function (error) {
					setError(
						(strings.errorPrefix || "Unable to run QA:") +
							" " +
							(error && error.message ? error.message : "Unknown error.")
					);
				})
				.finally(function () {
					setLoading(false);
				});
		});
	});
})();
