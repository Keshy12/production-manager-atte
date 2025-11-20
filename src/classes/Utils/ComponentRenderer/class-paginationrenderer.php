<?php
namespace Atte\Utils\ComponentRenderer;

class PaginationRenderer {
    private int $currentPage;
    private int $totalPages;
    private int $totalItems;
    private int $itemsPerPage;
    private string $baseUrl;
    private bool $useAjax;
    private array $options;

    /**
     * PaginationRenderer constructor
     *
     * @param int $currentPage Current page number (1-indexed)
     * @param int $totalItems Total number of items
     * @param int $itemsPerPage Number of items per page
     * @param array $options Additional options for customization
     */
    public function __construct(int $currentPage, int $totalItems, int $itemsPerPage = 20, array $options = []) {
        $this->currentPage = max(1, $currentPage);
        $this->totalItems = max(0, $totalItems);
        $this->itemsPerPage = max(1, $itemsPerPage);
        $this->totalPages = $this->itemsPerPage > 0 ? (int)ceil($this->totalItems / $this->itemsPerPage) : 1;

        // Ensure current page doesn't exceed total pages
        if ($this->currentPage > $this->totalPages && $this->totalPages > 0) {
            $this->currentPage = $this->totalPages;
        }

        // Default options
        $this->baseUrl = $options['baseUrl'] ?? '';
        $this->useAjax = $options['useAjax'] ?? true;
        $this->options = array_merge([
            'maxVisiblePages' => 5,
            'showFirstLast' => true,
            'showPrevNext' => true,
            'showPageSelect' => true,
            'showItemsInfo' => true,
            'prevText' => '<i class="bi bi-chevron-left"></i> Poprzednia',
            'nextText' => 'Następna <i class="bi bi-chevron-right"></i>',
            'firstText' => '<i class="bi bi-chevron-double-left"></i>',
            'lastText' => '<i class="bi bi-chevron-double-right"></i>',
            'size' => '', // 'sm' for small, 'lg' for large, '' for default
            'alignment' => 'center', // 'start', 'center', 'end'
            'containerClass' => '',
            'buttonClass' => 'btn-outline-primary',
            'activeButtonClass' => 'btn-primary',
        ], $options);
    }

    /**
     * Render complete pagination with all components
     *
     * @return void
     */
    public function render(): void {
        if ($this->totalPages <= 1) {
            return; // Don't show pagination if only one page
        }

        $alignment = $this->getAlignmentClass();
        $containerClass = $this->options['containerClass'];

        echo "<div class='d-flex flex-column align-items-{$alignment} {$containerClass} mb-3'>";

        // Items info (e.g., "Showing 1-20 of 100")
        if ($this->options['showItemsInfo']) {
            $this->renderItemsInfo();
        }

        // Main pagination buttons
        $this->renderPaginationButtons();

        // Page select dropdown
        if ($this->options['showPageSelect'] && $this->totalPages > 1) {
            $this->renderPageSelect();
        }

        echo "</div>";
    }

    /**
     * Render pagination buttons only
     *
     * @return void
     */
    public function renderPaginationButtons(): void {
        $size = $this->options['size'] ? "btn-group-{$this->options['size']}" : '';

        echo "<div class='btn-group {$size} mb-2' role='group' aria-label='Pagination'>";

        // First page button
        if ($this->options['showFirstLast']) {
            $this->renderButton(1, $this->options['firstText'], $this->currentPage === 1);
        }

        // Previous button
        if ($this->options['showPrevNext']) {
            $this->renderButton(
                $this->currentPage - 1,
                $this->options['prevText'],
                $this->currentPage === 1
            );
        }

        // Page number buttons
        $this->renderPageNumbers();

        // Next button
        if ($this->options['showPrevNext']) {
            $this->renderButton(
                $this->currentPage + 1,
                $this->options['nextText'],
                $this->currentPage === $this->totalPages
            );
        }

        // Last page button
        if ($this->options['showFirstLast']) {
            $this->renderButton(
                $this->totalPages,
                $this->options['lastText'],
                $this->currentPage === $this->totalPages
            );
        }

        echo "</div>";
    }

    /**
     * Render page number buttons with ellipsis
     *
     * @return void
     */
    private function renderPageNumbers(): void {
        $maxVisible = $this->options['maxVisiblePages'];
        $pages = $this->calculateVisiblePages($maxVisible);

        $lastPage = 0;
        foreach ($pages as $page) {
            // Add ellipsis if there's a gap
            if ($lastPage > 0 && $page > $lastPage + 1) {
                echo "<button class='btn {$this->options['buttonClass']} disabled' disabled>...</button>";
            }

            $isActive = $page === $this->currentPage;
            $buttonClass = $isActive ? $this->options['activeButtonClass'] : $this->options['buttonClass'];
            $disabled = $isActive ? 'disabled' : '';

            if ($this->useAjax) {
                echo "<button class='btn {$buttonClass} pagination-page' data-page='{$page}' {$disabled}>{$page}</button>";
            } else {
                $url = $this->buildUrl($page);
                echo "<a href='{$url}' class='btn {$buttonClass}' {$disabled}>{$page}</a>";
            }

            $lastPage = $page;
        }
    }

    /**
     * Render a single pagination button
     *
     * @param int $page Target page number
     * @param string $text Button text/HTML
     * @param bool $disabled Whether button is disabled
     * @return void
     */
    private function renderButton(int $page, string $text, bool $disabled): void {
        $buttonClass = $this->options['buttonClass'];
        $disabledAttr = $disabled ? 'disabled' : '';

        if ($this->useAjax) {
            echo "<button class='btn {$buttonClass} pagination-page' data-page='{$page}' {$disabledAttr}>{$text}</button>";
        } else {
            $url = $this->buildUrl($page);
            if ($disabled) {
                echo "<button class='btn {$buttonClass}' disabled>{$text}</button>";
            } else {
                echo "<a href='{$url}' class='btn {$buttonClass}'>{$text}</a>";
            }
        }
    }

    /**
     * Render page select dropdown
     *
     * @return void
     */
    public function renderPageSelect(): void {
        echo "<div class='mt-2'>";
        echo "<label class='mr-2 mb-0 small'>Przejdź do strony:</label>";

        if ($this->useAjax) {
            echo "<select class='custom-select custom-select-sm pagination-page-select' style='width: auto; display: inline-block;'>";
        } else {
            echo "<select class='custom-select custom-select-sm' onchange='window.location.href=this.value' style='width: auto; display: inline-block;'>";
        }

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $selected = $i === $this->currentPage ? 'selected' : '';

            if ($this->useAjax) {
                echo "<option value='{$i}' {$selected}>Strona {$i}</option>";
            } else {
                $url = $this->buildUrl($i);
                echo "<option value='{$url}' {$selected}>Strona {$i}</option>";
            }
        }

        echo "</select>";
        echo "</div>";
    }

    /**
     * Render items information (e.g., "Showing 1-20 of 100 items")
     *
     * @return void
     */
    public function renderItemsInfo(): void {
        $start = ($this->currentPage - 1) * $this->itemsPerPage + 1;
        $end = min($this->currentPage * $this->itemsPerPage, $this->totalItems);

        echo "<div class='text-muted small mb-2'>";
        echo "Wyświetlanie <strong>{$start}-{$end}</strong> z <strong>{$this->totalItems}</strong> elementów";
        echo "</div>";
    }

    /**
     * Calculate which page numbers should be visible
     *
     * @param int $maxVisible Maximum number of visible page buttons
     * @return array Array of page numbers to display
     */
    private function calculateVisiblePages(int $maxVisible): array {
        if ($this->totalPages <= $maxVisible) {
            return range(1, $this->totalPages);
        }

        $pages = [];
        $half = (int)floor($maxVisible / 2);

        // Always show first page
        $pages[] = 1;

        // Calculate range around current page
        $start = max(2, $this->currentPage - $half);
        $end = min($this->totalPages - 1, $this->currentPage + $half);

        // Adjust if we're near the beginning or end
        if ($this->currentPage <= $half + 1) {
            $end = min($maxVisible - 1, $this->totalPages - 1);
        } elseif ($this->currentPage >= $this->totalPages - $half) {
            $start = max(2, $this->totalPages - $maxVisible + 2);
        }

        // Add middle pages
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        // Always show last page
        if ($this->totalPages > 1) {
            $pages[] = $this->totalPages;
        }

        return array_unique($pages);
    }

    /**
     * Build URL for a specific page
     *
     * @param int $page Page number
     * @return string URL with page parameter
     */
    private function buildUrl(int $page): string {
        if (empty($this->baseUrl)) {
            return "?page={$page}";
        }

        $separator = strpos($this->baseUrl, '?') !== false ? '&' : '?';
        return "{$this->baseUrl}{$separator}page={$page}";
    }

    /**
     * Get Bootstrap alignment class
     *
     * @return string Alignment class
     */
    private function getAlignmentClass(): string {
        $map = [
            'start' => 'start',
            'center' => 'center',
            'end' => 'end',
            'left' => 'start',
            'right' => 'end',
        ];

        return $map[$this->options['alignment']] ?? 'center';
    }

    /**
     * Get current page number
     *
     * @return int Current page
     */
    public function getCurrentPage(): int {
        return $this->currentPage;
    }

    /**
     * Get total number of pages
     *
     * @return int Total pages
     */
    public function getTotalPages(): int {
        return $this->totalPages;
    }

    /**
     * Check if there is a next page
     *
     * @return bool True if next page exists
     */
    public function hasNextPage(): bool {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * Check if there is a previous page
     *
     * @return bool True if previous page exists
     */
    public function hasPreviousPage(): bool {
        return $this->currentPage > 1;
    }

    /**
     * Get offset for database queries (0-indexed)
     *
     * @return int Offset value
     */
    public function getOffset(): int {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    /**
     * Get limit for database queries
     *
     * @return int Limit value
     */
    public function getLimit(): int {
        return $this->itemsPerPage;
    }
}