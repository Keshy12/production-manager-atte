// Handle tab switching based on URL hash fragments
$(document).ready(function() {
    // Activate tab based on URL hash on page load
    const hash = window.location.hash;
    
    // Check if hash matches a valid tab ID
    if (hash === '#update-prices' || hash === '#import-orders' || hash === '#integration') {
        // Find the tab link by href and show it
        $(`a[href="${hash}"]`).tab('show');
    }
    
    // Listen for hash changes (e.g., when user clicks back/forward buttons)
    $(window).on('hashchange', function() {
        const newHash = window.location.hash;
        
        // Only switch tabs for valid tab hashes
        if (newHash === '#update-prices' || newHash === '#import-orders' || newHash === '#integration') {
            $(`a[href="${newHash}"]`).tab('show');
        }
    });
});
