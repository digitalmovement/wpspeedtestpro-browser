jQuery(document).ready(function($) {
    
    // Test S3 connection
    $('#test-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#connection-result');
        
        $button.text('Testing...').prop('disabled', true);
        $result.hide().removeClass('success error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_test_connection',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Test Connection').prop('disabled', false);
            
            if (response.success) {
                $result.addClass('success').text(response.message).show();
            } else {
                $result.addClass('error').text(response.message).show();
            }
        });
    });
    
    // Scan S3 bucket
    $('#scan-bucket').on('click', function() {
        var $button = $(this);
        var $result = $('#scan-results');
        
        $button.text('Scanning...').prop('disabled', true);
        $result.hide().removeClass('success error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_scan_bucket',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Scan S3 Bucket').prop('disabled', false);
            
            if (response.success) {
                var data = response.data;
                var message = data.message || 'Scan completed! Processed: ' + data.processed + ', New Bug Reports: ' + data.new_bug_reports;
                $result.addClass('success').html(message).show();
                setTimeout(function() { window.location.reload(); }, 3000);
            } else {
                $result.addClass('error').text('Scan failed: ' + response.data).show();
            }
        });
    });
    
    // Bug report modal functionality
    var $modal = $('<div id="wpstb-bug-modal" class="wpstb-modal"></div>');
    $('body').append($modal);
    
    $('.view-report').on('click', function() {
        var reportId = $(this).data('id');
        showBugReportModal(reportId);
    });
    
    function showBugReportModal(reportId) {
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_get_bug_report',
            id: reportId,
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                var report = response.data;
                var modalContent = buildBugReportModal(report);
                $modal.html(modalContent).show();
            }
        });
    }
    
    function buildBugReportModal(report) {
        return '<div class="wpstb-modal-content">' +
               '<div class="wpstb-modal-header">' +
               '<span class="wpstb-close">&times;</span>' +
               '<h2>Bug Report #' + report.id + '</h2>' +
               '</div>' +
               '<div class="wpstb-modal-body">' +
               '<p><strong>Email:</strong> ' + (report.email || 'N/A') + '</p>' +
               '<p><strong>Message:</strong> ' + (report.message || 'N/A') + '</p>' +
               '<p><strong>Site:</strong> <a href="' + report.site_url + '" target="_blank">' + report.site_url + '</a></p>' +
               '<label>Status:</label>' +
               '<select id="bug-status">' +
               '<option value="open"' + (report.status === 'open' ? ' selected' : '') + '>Open</option>' +
               '<option value="resolved"' + (report.status === 'resolved' ? ' selected' : '') + '>Resolved</option>' +
               '</select>' +
               '<label>Notes:</label>' +
               '<textarea id="bug-notes">' + (report.admin_notes || '') + '</textarea>' +
               '<button class="button button-primary" id="save-bug-report" data-id="' + report.id + '">Save</button>' +
               '</div>' +
               '</div>';
    }
    
    // Modal close functionality
    $(document).on('click', '.wpstb-close', function() {
        $modal.hide();
    });
    
    // Save bug report
    $(document).on('click', '#save-bug-report', function() {
        var reportId = $(this).data('id');
        var status = $('#bug-status').val();
        var notes = $('#bug-notes').val();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_update_bug_status',
            id: reportId,
            status: status,
            notes: notes,
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                $modal.hide();
                window.location.reload();
            }
        });
    });
    
    // Initialize charts if available
    if (typeof wpstb_analytics !== 'undefined') {
        initializeCharts();
    }
    
    function initializeCharts() {
        if (typeof Chart === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = createCharts;
            document.head.appendChild(script);
        } else {
            createCharts();
        }
    }
    
    function createCharts() {
        if (wpstb_analytics.wp_versions && wpstb_analytics.wp_versions.length > 0) {
            createChart('wp-versions-chart', wpstb_analytics.wp_versions, 'doughnut');
        }
        if (wpstb_analytics.countries && wpstb_analytics.countries.length > 0) {
            createChart('countries-chart', wpstb_analytics.countries, 'bar');
        }
    }
    
    function createChart(canvasId, data, type) {
        var ctx = document.getElementById(canvasId);
        if (!ctx) return;
        
        var labels = data.map(function(item) { return item.wp_version || item.country; });
        var values = data.map(function(item) { return parseInt(item.count); });
        
        new Chart(ctx, {
            type: type,
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
    
    // Update hosting providers
    $(document).on('click', '#update-providers', function() {
        var $button = $(this);
        
        $button.text('Updating...').prop('disabled', true);
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_update_providers',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Update Providers').prop('disabled', false);
            
            if (response.success) {
                alert('Hosting providers updated successfully!');
                window.location.reload();
            } else {
                alert('Failed to update providers: ' + response.data);
            }
        }).fail(function() {
            $button.text('Update Providers').prop('disabled', false);
            alert('Request failed');
        });
    });
    
    // Clear providers cache
    $(document).on('click', '#clear-providers-cache', function() {
        var $button = $(this);
        
        if (!confirm('Are you sure you want to clear the providers cache?')) {
            return;
        }
        
        $button.text('Clearing...').prop('disabled', true);
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_clear_providers_cache',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Clear Cache').prop('disabled', false);
            
            if (response.success) {
                alert('Providers cache cleared successfully!');
                window.location.reload();
            } else {
                alert('Failed to clear cache: ' + response.data);
            }
        }).fail(function() {
            $button.text('Clear Cache').prop('disabled', false);
            alert('Request failed');
        });
    });
    
    // Run S3 diagnostics
    $(document).on('click', '#run-diagnostics', function() {
        var $button = $(this);
        var $result = $('#diagnostics-result');
        
        $button.text('Running Diagnostics...').prop('disabled', true);
        $result.hide().removeClass('success error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_run_diagnostics',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Run S3 Diagnostics').prop('disabled', false);
            
            if (response.success) {
                var diagnostics = response.data;
                var html = '<div class="diagnostics-report"><h4>Diagnostic Results</h4>';
                
                // WordPress info
                html += '<h5>WordPress Environment</h5><ul>';
                for (var key in diagnostics.wordpress) {
                    html += '<li><strong>' + key + ':</strong> ' + diagnostics.wordpress[key] + '</li>';
                }
                html += '</ul>';
                
                // PHP info
                html += '<h5>PHP Environment</h5><ul>';
                for (var key in diagnostics.php) {
                    html += '<li><strong>' + key + ':</strong> ' + diagnostics.php[key] + '</li>';
                }
                html += '</ul>';
                
                // S3 Configuration
                html += '<h5>S3 Configuration</h5><ul>';
                for (var key in diagnostics.s3_config) {
                    html += '<li><strong>' + key + ':</strong> ' + diagnostics.s3_config[key] + '</li>';
                }
                html += '</ul>';
                
                // S3 Connection Test
                html += '<h5>S3 Connection Test</h5>';
                if (diagnostics.s3_connection.success) {
                    html += '<p style="color: green;">✓ ' + diagnostics.s3_connection.message + '</p>';
                    
                    if (diagnostics.s3_sample_objects && Array.isArray(diagnostics.s3_sample_objects)) {
                        html += '<p><strong>Sample Objects Found:</strong></p><ul>';
                        diagnostics.s3_sample_objects.forEach(function(obj) {
                            html += '<li>' + obj.Key + ' (' + obj.Size + ' bytes)</li>';
                        });
                        html += '</ul>';
                    }
                } else {
                    html += '<p style="color: red;">✗ ' + diagnostics.s3_connection.message + '</p>';
                }
                
                // Database Tables
                html += '<h5>Database Tables</h5><ul>';
                for (var table in diagnostics.database_tables) {
                    var status = diagnostics.database_tables[table];
                    var icon = status.exists ? '✓' : '✗';
                    var color = status.exists ? 'green' : 'red';
                    html += '<li style="color: ' + color + ';">' + icon + ' ' + table + ' (' + status.count + ' records)</li>';
                }
                html += '</ul>';
                
                html += '</div>';
                
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').text('Diagnostics failed: ' + response.data).show();
            }
        }).fail(function() {
            $button.text('Run S3 Diagnostics').prop('disabled', false);
            $result.addClass('error').text('Diagnostics request failed').show();
        });
    });
    
}); 