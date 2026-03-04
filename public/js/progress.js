/**
 * Progress tracking for database comparison
 */
document.addEventListener('DOMContentLoaded', () => {
  const progressContainer = document.getElementById('progress-container');

  if (!progressContainer) return;

  const progressBar = document.getElementById('progress-bar-fill');
  const progressPercent = document.getElementById('progress-percent');
  const progressStatus = document.getElementById('progress-status-text');
  const progressLog = document.getElementById('progress-log');
  const errorAlert = document.getElementById('progress-error');
  const errorMessage = document.getElementById('progress-error-message');

  let currentRunId = null;
  let pollInterval = null;
  let lastSeenStepCount = 0;

  /**
   * Appends a message to the progress log (newest at top)
   */
  const addLogEntry = (message, time) => {
    const entry = document.createElement('div');
    entry.className = 'progress-log-item';
    const timeStr = time
      ? new Date(time).toLocaleTimeString()
      : new Date().toLocaleTimeString();
    entry.innerHTML = `<span class="log-time">[${timeStr}]</span> ${message}`;
    progressLog.prepend(entry);
  };

  /**
   * Updates the progress UI from an API response object
   */
  const updateUI = (data) => {
    const percent = data.progressPercent || 0;
    progressBar.style.width = `${percent}%`;
    progressPercent.textContent = `${percent}%`;

    if (data.latestMessage) {
      progressStatus.textContent = data.latestMessage;
    }

    // Render any new steps that arrived since last poll
    if (Array.isArray(data.steps) && data.steps.length > lastSeenStepCount) {
      const newSteps = data.steps.slice(lastSeenStepCount);
      // Steps are in ascending order; we prepend so newest ends up on top
      // We reverse so that after prepending the order remains chronological top-to-bottom
      for (let i = newSteps.length - 1; i >= 0; i--) {
        addLogEntry(newSteps[i].message, newSteps[i].created_at);
      }
      lastSeenStepCount = data.steps.length;
    }

    if (data.status === 'completed') {
      clearInterval(pollInterval);
      addLogEntry('Comparison completed successfully! Redirecting...');
      setTimeout(() => {
        window.location.href = `index.php?runId=${currentRunId}`;
      }, 1000);
    } else if (data.status === 'failed') {
      clearInterval(pollInterval);
      progressContainer.classList.add('hidden');
      errorAlert.classList.remove('hidden');
      errorMessage.textContent =
        data.errorMessage || 'An unknown error occurred during comparison.';
    }
  };

  /**
   * Polls the progress API, requesting all steps each time
   */
  const pollProgress = (runId) => {
    pollInterval = setInterval(async () => {
      try {
        const response = await fetch(
          `api/progress.php?runId=${runId}&includeSteps=true`
        );
        if (!response.ok) throw new Error('Failed to fetch progress');

        const data = await response.json();
        updateUI(data);
      } catch (error) {
        console.error('Polling error:', error);
      }
    }, 1000);
  };

  /**
   * Starts the comparison process
   */
  const startComparison = async () => {
    try {
      addLogEntry('Initiating comparison request...');
      const response = await fetch('api/start_comparison.php', {
        method: 'POST',
      });

      if (!response.ok) throw new Error('Failed to start comparison');

      const data = await response.json();
      currentRunId = data.runId;
      addLogEntry(`Run ID ${currentRunId} created. Starting analysis...`);
      pollProgress(currentRunId);
    } catch (error) {
      progressContainer.classList.add('hidden');
      errorAlert.classList.remove('hidden');
      errorMessage.textContent = error.message;
      console.error('Start error:', error);
    }
  };

  // Start the process automatically if we're in the loading state
  if (window.location.search.indexOf('runId') === -1) {
    startComparison();
  }
});

