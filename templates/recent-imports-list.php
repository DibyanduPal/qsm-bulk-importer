<?php
/**
 * templates/recent-imports-list.php
 *
 * Recent Imports list template — UPDATED
 *
 * Responsibilities:
 *  - Display recent import records (table)
 *  - Provide actions: View (single import), View Errors (ThickBox modal), Rollback (with confirmation + nonce)
 *  - Render error details in a readable format (no "Array(...)" dumps)
 *
 * Expected variables provided by the controller:
 *  - $items   : array|WPDB result (rows)
 *  - $total   : int total rows
 *  - $per_page: int rows per page
 *  - $paged   : int current page
 *  - $base_url: string base URL for links (admin.php?page=...)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* Ensure defaults — controller should override these */
$items    = isset( $items ) ? $items : array();
$total    = isset( $total ) ? intval( $total ) : 0;
$paged    = isset( $paged ) ? intval( $paged ) : 1;
$per_page = isset( $per_page ) ? intval( $per_page ) : 20;
$base_url = isset( $base_url ) ? $base_url : admin_url( 'admin.php?page=qsm-bulk-import/recent' );
?>

<div class="wrap">
    <h1><?php esc_html_e( 'QSM Bulk Import — Recent Imports', 'qsm-bulk-importer' ); ?></h1>

    <?php if ( empty( $items ) ) : ?>
        <div class="notice notice-info"><p><?php esc_html_e( 'No import records found.', 'qsm-bulk-importer' ); ?></p></div>
    <?php else : ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'File', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'Quiz', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'Imported At', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'Counts', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'qsm-bulk-importer' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'qsm-bulk-importer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $row ) :
                    // Normalize fields (fall back in case schema differs)
                    $row_id        = isset( $row->id ) ? intval( $row->id ) : 0;
                    $file_name     = isset( $row->file_name ) ? $row->file_name : '';
                    $quiz_name     = isset( $row->quiz_name ) ? $row->quiz_name : '';
                    $import_time   = isset( $row->import_time ) ? $row->import_time : '';
                    // **Use success_rows instead of imported_rows**:
                    $imported_rows = isset( $row->success_rows ) ? intval( $row->success_rows ) : 0;
                    $failed_rows   = isset( $row->failed_rows ) ? intval( $row->failed_rows ) : 0;
                    $status        = isset( $row->status ) ? $row->status : '';
                    $question_ids  = isset( $row->question_ids ) ? $row->question_ids : '';
                    $raw_errors    = isset( $row->errors ) ? $row->errors : '';
                    $errors_parsed = maybe_unserialize( $raw_errors );
                    // Unique inline modal ID for this row
                    $inline_id = 'qsm-errors-' . (int) $row_id;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row_id ); ?></td>
                        <td><?php echo esc_html( $file_name ); ?></td>
                        <td><?php echo esc_html( $quiz_name ); ?></td>
                        <td><?php echo esc_html( $import_time ); ?></td>
                        <td><?php echo esc_html( sprintf( '%d imported / %d failed', $imported_rows, $failed_rows ) ); ?></td>
                        <td><?php echo esc_html( $status ); ?></td>
                        <td>
                            <!-- View: link to same controller with log_id parameter -->
                            <a href="<?php echo esc_url( add_query_arg( array( 'log_id' => $row_id ), admin_url( 'admin.php?page=qsm-bulk-import/recent' ) ) ); ?>" class="button"><?php esc_html_e( 'View', 'qsm-bulk-importer' ); ?></a>
                            &nbsp;
                            <!-- Rollback: only if question_ids exists and status not already rolled back -->
                            <?php if ( ! empty( $question_ids ) && 'rolledback' !== strtolower( $status ) ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" class="qsm-rollback-form">
                                    <?php wp_nonce_field( 'qsm_bulk_rollback_action', 'qsm_bulk_rollback_nonce' ); ?>
                                    <input type="hidden" name="action" value="qsm_bulk_rollback">
                                    <input type="hidden" name="log_id" value="<?php echo esc_attr( $row_id ); ?>">
                                    <button type="submit" class="button"><?php esc_html_e( 'Rollback', 'qsm-bulk-importer' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Inline ThickBox modal for errors -->
                    <div id="<?php echo esc_attr( $inline_id ); ?>" style="display:none;">
                        <div style="max-width:600px;">
                            <?php if ( ! empty( $errors_parsed ) && is_array( $errors_parsed ) ) : ?>
                                <h3><?php esc_html_e( 'Import Errors', 'qsm-bulk-importer' ); ?></h3>
                                <ul>
                                <?php foreach ( $errors_parsed as $err ) : ?>
                                    <li><?php echo esc_html( $err ); ?></li>
                                <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p><?php esc_html_e( 'No errors recorded.', 'qsm-bulk-importer' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Pagination links
        $page_links = paginate_links( array(
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'prev_text' => __('&laquo; Prev'),
            'next_text' => __('Next &raquo;'),
            'total'     => ceil( $total / $per_page ),
            'current'   => $paged
        ) );
        if ( $page_links ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
        }
        ?>

    <?php endif; ?>
</div>
