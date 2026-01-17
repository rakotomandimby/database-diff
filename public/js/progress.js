/**
 * Progress tracking for database comparison
 */
document.addEventListener('DOMContentLoaded', () => {
  const progressContainer = document.getElementById('progress-container');
  const resultsContainer = document.getElementById('results-container');
  
  if (!progressContainer) return;

  const progressBar = document.getElementById('progress-bar-fill');
  const progressPercent = document.getElementById('progress-percent');
  const progressStatus = document.getElementById('progress-status-text');
  const progressLog = document.getElementById('progress-log');
  const errorAlert = document.getElementById('progress-error');
  const errorMessage = document.getElementById('progress-error-message');

  let currentRunId = null;
  let pollInterval = null;

  /**
   * Appends a message to the progress log
   */
  const addLogEntry = (message) => {
    const entry = document.createElement('div');
    entry.className = 'progress-log-item';
    const time = new Date().toLocaleTimeString();
    entry.innerHTML = `<span class="log-time">[${time}]</span> ${message}`;
    progressLog.prepend(entry);
  };

  /**
   * Updates the progress UI
   */
  const updateUI = (data) => {
    const percent = data.progress_percent || 0;
    progressBar.style.width = `${percent}%`;
    progressPercent.textContent = `${percent}%`;
    
    if (data.message) {
      progressStatus.textContent = data.message;
      // Only log if message changed
      if (progressLog.firstChild?.textContent.indexOf(data.message) === -1) {
        addLogEntry(data.message);
      }
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
      errorMessage.textContent = data.error_message || 'An unknown error occurred during comparison.';
    }
  };

  /**
   * Polls the progress API
   */
  const pollProgress = (runId) => {
    pollInterval = setInterval(async () => {
      try {
        const response = await fetch(`api/progress.php?runId=${runId}`);
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
        method: 'POST'
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

