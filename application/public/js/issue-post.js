(() => {
 if (window.issuePostSharingReady) return;
 window.issuePostSharingReady = true;
 document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-share-issue]');
  if (!button) return;
  const status = button.closest('.post-actions').querySelector('.post-share-status');
  const url = button.dataset.shareIssue;
  try {
   if (navigator.share) {
    await navigator.share({title: button.dataset.shareTitle, url});
    status.textContent = 'Share options opened.';
   } else if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(url);
    status.textContent = 'Report link copied.';
   } else { status.textContent = 'Share this report: ' + url; }
  } catch (error) {
   status.textContent = error.name === 'AbortError' ? '' : 'Share this report: ' + url;
  }
 });
})();
