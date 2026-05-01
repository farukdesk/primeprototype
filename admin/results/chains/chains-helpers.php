<?php
/**
 * Shared helpers for Workflow Chain pages.
 */

/**
 * Replace all steps for a given chain.
 */
function _save_chain_steps(int $chain_id, array $steps, $db): void
{
    $db->prepare('DELETE FROM wf_chain_steps WHERE chain_id = ?')->execute([$chain_id]);
    $ins = $db->prepare(
        'INSERT INTO wf_chain_steps (chain_id, step_order, step_label, group_id, is_entry, is_final)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($steps as $i => $step) {
        $ins->execute([
            $chain_id,
            $i + 1,
            trim($step['label']),
            (int)$step['group_id'],
            !empty($step['is_entry']) ? 1 : 0,
            !empty($step['is_final']) ? 1 : 0,
        ]);
    }
}
