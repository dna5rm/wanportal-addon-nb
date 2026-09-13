<?php
/**
 * src/netbox/helpers.php
 * Naming and block utility functions. Container naming (<account>-<region>)
 * and parent naming (<environment>_<region>), plus free-block filtering and
 * size sorting that stay compatible with the legacy ipcontrol.php field
 * names (blockAddr, blockSize, blockStatus).
 */

/**
 * Format account container name.
 * Produces: "<account>-<region>" (e.g. "example01-eu-west-1")
 *
 * @param string $account Account name
 * @param string $region  Region name
 * @return string
 */
function format_container_name(string $account, string $region): string {
    return sprintf('%s-%s',
        strtolower(trim($account)),
        strtolower(trim($region))
    );
}

/**
 * Format parent container name.
 * Produces: "<environment>_<region>" (e.g. "aws-development_eu-west-1")
 *
 * @param string $environment Environment name
 * @param string $region      Region name
 * @return string
 */
function format_parent_container(string $environment, string $region): string {
    return sprintf('%s_%s',
        strtolower(trim($environment)),
        strtolower(trim($region))
    );
}

/**
 * Filter free (unallocated) blocks from a formatted block array.
 * Mirrors the legacy filter_free_blocks() signature from ipcontrol.php.
 *
 * @param array $blocks Formatted block array (blockAddr, blockSize, blockStatus)
 * @return array
 */
function filter_free_blocks(array $blocks): array {
    return array_filter($blocks, function($block) {
        return isset($block['blockStatus']) && $block['blockStatus'] === 'Free';
    });
}

/**
 * Sort blocks by prefix size ascending (smallest prefix number = largest block).
 * e.g. /21 sorts before /24.
 *
 * @param array $blocks
 * @return array
 */
function sort_blocks_by_size(array $blocks): array {
    usort($blocks, function($a, $b) {
        return (int)$a['blockSize'] <=> (int)$b['blockSize'];
    });
    return $blocks;
}

/**
 * Validate IP prefix/block size (0-32).
 *
 * @param string $size
 * @return bool
 */
function validate_block_size(string $size): bool {
    $size = (int)$size;
    return $size >= 0 && $size <= 32;
}
