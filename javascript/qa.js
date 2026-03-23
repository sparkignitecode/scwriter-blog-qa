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

	function getTrimmedLocation(locationInput) {
		if (!locationInput || typeof locationInput.value !== "string") {
			return "";
		}

		return locationInput.value.trim();
	}

	function getTrimmedKeywordCluster(keywordClusterInput) {
		if (!keywordClusterInput || typeof keywordClusterInput.value !== "string") {
			return "";
		}

		return keywordClusterInput.value.trim();
	}

	onReady(function () {
		var data = window.scwriterBlogQaData || {};
		var strings = data.strings || {};
		var root = document.getElementById("blogqa-meta-box");
		var locationInput = document.getElementById("blogqa-location");
		var keywordClusterInput = document.getElementById("blogqa-keyword-cluster");
		var runButton = document.getElementById("blogqa-run-button");
		var spinner = document.getElementById("blogqa-spinner");
		var scoreNode = document.getElementById("blogqa-score");
		var lastRunNode = document.getElementById("blogqa-last-run");
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

		function renderResults(results) {
			if (!Array.isArray(results) || !results.length) {
				renderEmptyState();
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

		if (locationInput && data.location && !locationInput.value) {
			locationInput.value = String(data.location);
		}

		if (keywordClusterInput && data.keywordCluster && !keywordClusterInput.value) {
			keywordClusterInput.value = String(data.keywordCluster);
		}

		renderResults(Array.isArray(data.initialResults) ? data.initialResults : []);
		lastRunNode.textContent = formatLastRun(data.lastRun, strings);

		runButton.addEventListener("click", function () {
			if (!data.restUrl) {
				setError("Missing REST configuration for SCwriter Blog QA.");
				return;
			}

			var locationValue = getTrimmedLocation(locationInput);
			var keywordClusterValue = getTrimmedKeywordCluster(keywordClusterInput);

			if (!locationValue) {
				setError(strings.locationRequired || "Location is required to run QA.");

				if (locationInput) {
					locationInput.focus();
				}

				return;
			}

			if (!keywordClusterValue) {
				setError(
					strings.keywordClusterRequired || "Keyword cluster is required to run QA."
				);

				if (keywordClusterInput) {
					keywordClusterInput.focus();
				}

				return;
			}

			clearError();
			setLoading(true);

			fetch(data.restUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": data.nonce || "",
				},
				body: JSON.stringify({
					location: locationValue,
					keyword_cluster: keywordClusterValue,
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

					renderResults(results);
					lastRunNode.textContent = formatLastRun(lastRun, strings);
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
