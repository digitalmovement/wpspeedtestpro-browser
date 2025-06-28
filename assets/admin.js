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
    
    // Scan S3 bucket with progress feedback
    $('#scan-bucket').on('click', function() {
        var $button = $(this);
        var $result = $('#scan-results');
        
        $button.text('Initializing Scan...').prop('disabled', true);
        $result.removeClass('success error').html(
            '<div class="scan-progress">' +
            '<div class="scan-status">Starting S3 bucket scan...</div>' +
            '<div class="scan-spinner" style="margin: 10px 0;">' +
            '<span class="spinner is-active" style="float: left; margin-right: 10px;"></span>' +
            '<span class="scan-progress-text">Connecting to S3...</span>' +
            '</div>' +
            '</div>'
        ).show();
        
        var startTime = Date.now();
        var progressInterval = setInterval(function() {
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            $('.scan-progress-text').text('Scanning in progress... (' + elapsed + 's elapsed)');
        }, 1000);
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_scan_bucket',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            clearInterval(progressInterval);
            $button.text('Scan S3 Bucket').prop('disabled', false);
            
            if (response.success) {
                var data = response.data;
                var elapsed = Math.floor((Date.now() - startTime) / 1000);
                
                var detailedMessage = '<div class="scan-complete">' +
                    '<h4 style="color: #46b450; margin: 0 0 10px 0;">✓ Scan Completed Successfully!</h4>' +
                    '<div class="scan-stats" style="background: #f9f9f9; padding: 10px; border-left: 4px solid #46b450; margin-bottom: 10px;">' +
                    '<p style="margin: 0;"><strong>Total Time:</strong> ' + elapsed + ' seconds</p>' +
                    '<p style="margin: 5px 0 0 0;"><strong>Objects Found:</strong> ' + data.total_objects + '</p>' +
                    '</div>' +
                    '<div class="scan-results-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">' +
                    '<div style="background: #e7f3ff; padding: 8px; border-radius: 4px;"><strong>Processed:</strong> ' + data.processed + '</div>' +
                    '<div style="background: #f0f9ff; padding: 8px; border-radius: 4px;"><strong>Skipped:</strong> ' + data.skipped + '</div>' +
                    '<div style="background: #e8f5e8; padding: 8px; border-radius: 4px;"><strong>Bug Reports:</strong> ' + data.new_bug_reports + '</div>' +
                    '<div style="background: #fff2e8; padding: 8px; border-radius: 4px;"><strong>Diagnostic Files:</strong> ' + data.new_diagnostic_files + '</div>' +
                    '</div>';
                
                if (data.errors > 0) {
                    detailedMessage += '<div style="background: #ffebee; padding: 8px; border-left: 4px solid #f44336; margin-top: 10px;">' +
                        '<strong>Errors:</strong> ' + data.errors + ' files failed to process' +
                        '</div>';
                }
                
                detailedMessage += '<p style="margin-top: 15px; font-style: italic; color: #666;">Page will refresh in 3 seconds to show new data...</p></div>';
                
                $result.addClass('success').html(detailedMessage);
                setTimeout(function() { window.location.reload(); }, 3000);
            } else {
                $result.addClass('error').html(
                    '<div class="scan-error">' +
                    '<h4 style="color: #d63638; margin: 0 0 10px 0;">✗ Scan Failed</h4>' +
                    '<p style="margin: 0;">' + response.data + '</p>' +
                    '</div>'
                );
            }
        }).fail(function(xhr, status, error) {
            clearInterval(progressInterval);
            $button.text('Scan S3 Bucket').prop('disabled', false);
            $result.addClass('error').html(
                '<div class="scan-error">' +
                '<h4 style="color: #d63638; margin: 0 0 10px 0;">✗ Scan Request Failed</h4>' +
                '<p style="margin: 0;">Network error or timeout occurred. Please try again.</p>' +
                '</div>'
            );
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
        // WordPress versions chart
        if (wpstb_analytics && wpstb_analytics.wp_versions && wpstb_analytics.wp_versions.length > 0) {
            createChart('wp-versions-chart', wpstb_analytics.wp_versions, 'doughnut', 'wp_version');
        } else {
            showNoDataMessage('wp-versions-chart', 'No WordPress version data available');
        }
        
        // PHP versions chart  
        if (wpstb_analytics && wpstb_analytics.php_versions && wpstb_analytics.php_versions.length > 0) {
            createChart('php-versions-chart', wpstb_analytics.php_versions, 'doughnut', 'php_version');
        } else {
            showNoDataMessage('php-versions-chart', 'No PHP version data available');
        }
        
        // Countries chart
        if (wpstb_analytics && wpstb_analytics.countries && wpstb_analytics.countries.length > 0) {
            createChart('countries-chart', wpstb_analytics.countries, 'bar', 'country');
        } else {
            showNoDataMessage('countries-chart', 'No country data available');
        }
    }
    
    function showNoDataMessage(canvasId, message) {
        var canvas = document.getElementById(canvasId);
        if (canvas && canvas.parentElement) {
            canvas.parentElement.innerHTML = '<p style="text-align: center; color: #666; padding: 40px; font-style: italic;">' + message + '</p>';
        }
    }
    
    // Store chart instances to prevent duplicates
    var chartInstances = {};
    
    function createChart(canvasId, data, type, labelField) {
        var ctx = document.getElementById(canvasId);
        if (!ctx) {
            console.warn('Chart canvas not found:', canvasId);
            return;
        }
        

        
        // Destroy existing chart instance if it exists
        if (chartInstances[canvasId]) {
            chartInstances[canvasId].destroy();
        }
        
        // Ensure the canvas container has proper dimensions
        var container = ctx.parentElement;
        if (container) {
            container.style.position = 'relative';
            container.style.height = type === 'bar' ? '400px' : '300px';
            container.style.width = '100%';
            container.style.overflow = 'hidden';
        }
        
        // Reset canvas dimensions
        ctx.style.maxHeight = type === 'bar' ? '400px' : '300px';
        ctx.style.maxWidth = '100%';
        
        // Extract labels and values based on the field
        var labels = data.map(function(item) { 
            if (labelField) {
                return item[labelField] || 'Unknown';
            }
            return item.wp_version || item.php_version || item.country || 'Unknown'; 
        });
        var values = data.map(function(item) { 
            return parseInt(item.count) || 0; 
        });
        

        
        // Generate more colors for larger datasets
        var colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'];
        while (colors.length < labels.length) {
            colors = colors.concat(colors);
        }
        
        try {
            var chartConfig = {
                type: type,
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 0 // Disable animations to prevent render loops
                    },
                    plugins: {
                        legend: {
                            display: type !== 'bar' || labels.length <= 10,
                            position: 'bottom'
                        }
                    }
                }
            };
            
            // Add specific options for bar charts
            if (type === 'bar') {
                chartConfig.options.scales = {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 0
                        }
                    }
                };
                
                // Limit bar chart data to prevent overcrowding
                if (labels.length > 20) {
                    chartConfig.data.labels = labels.slice(0, 20);
                    chartConfig.data.datasets[0].data = values.slice(0, 20);
                    chartConfig.data.datasets[0].backgroundColor = colors.slice(0, 20);
                }
            }
            
            chartInstances[canvasId] = new Chart(ctx, chartConfig);
            
        } catch (error) {
            console.error('Error creating chart:', canvasId, error);
            ctx.parentElement.innerHTML = '<p style="color: #d63638; padding: 20px; text-align: center;">Error rendering chart: ' + error.message + '</p>';
        }
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