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
                    (data.total_directories ? '<p style="margin: 5px 0 0 0;"><strong>Directories Found:</strong> ' + data.total_directories + '</p>' : '') +
                    '</div>' +
                    '<div class="scan-results-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">' +
                    '<div style="background: #e7f3ff; padding: 8px; border-radius: 4px;"><strong>Processed:</strong> ' + data.processed + '</div>' +
                    '<div style="background: #f0f9ff; padding: 8px; border-radius: 4px;"><strong>Skipped:</strong> ' + data.skipped + '</div>' +
                    '<div style="background: #e8f5e8; padding: 8px; border-radius: 4px;"><strong>Bug Reports:</strong> ' + data.new_bug_reports + '</div>' +
                    '<div style="background: #fff2e8; padding: 8px; border-radius: 4px;"><strong>Diagnostic Directories:</strong> ' + data.new_diagnostic_files + '</div>' +
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
    
    function createChart(canvasId, data, type, labelField) {
        var ctx = document.getElementById(canvasId);
        if (!ctx) {
            console.warn('Chart canvas not found:', canvasId);
            return;
        }
        

        
        // Destroy existing chart instance if it exists
        if (ctx.chart) {
            ctx.chart.destroy();
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
            
            ctx.chart = new Chart(ctx, chartConfig);
            
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
    
    // Debug S3 files
    $(document).on('click', '#debug-s3-files', function() {
        var $button = $(this);
        var $result = $('#s3-files-debug');
        
        $button.text('Analyzing S3 Files...').prop('disabled', true);
        $result.hide().removeClass('success error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_debug_s3_files',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Debug S3 Files').prop('disabled', false);
            
            if (response.success) {
                var data = response.data;
                var html = '<div class="s3-files-debug-report"><h4>S3 Files Analysis</h4>';
                
                // Summary
                html += '<div style="background: #f0f0f1; padding: 15px; margin: 10px 0; border: 1px solid #ccd0d4;">';
                html += '<h5>Comprehensive Scan Summary</h5>';
                html += '<p><strong>Total Files Scanned:</strong> ' + data.total_files + '</p>';
                html += '<p><strong>Files from Root Search:</strong> ' + data.search_details.total_objects_found + '</p>';
                html += '<p><strong>Files from bug-reports/ Search:</strong> ' + data.search_details.bug_reports_folder_search + '</p>';
                html += '<p><strong>JSON Files:</strong> ' + data.analysis.total_json_files + '</p>';
                html += '<p><strong>Bug Reports Detected:</strong> ' + data.analysis.bug_reports_found + '</p>';
                html += '<p><strong>Diagnostic Files Detected:</strong> ' + data.analysis.diagnostic_files_found + '</p>';
                html += '<p><strong>Non-JSON Files:</strong> ' + data.analysis.non_json_files + '</p>';
                html += '</div>';
                
                // Folder Structure
                if (Object.keys(data.folder_structure).length > 0) {
                    html += '<div style="background: #e7f3ff; padding: 15px; margin: 10px 0; border: 1px solid #b3d9ff;">';
                    html += '<h5>Folder Structure Analysis</h5>';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="background: #cce7ff;"><th style="border: 1px solid #99ccff; padding: 8px;">Folder</th><th style="border: 1px solid #99ccff; padding: 8px;">File Count</th></tr></thead>';
                    html += '<tbody>';
                    
                    for (var folder in data.folder_structure) {
                        var rowStyle = folder.toLowerCase().includes('bug') ? 'background: #ffebcc;' : '';
                        html += '<tr style="' + rowStyle + '">';
                        html += '<td style="border: 1px solid #99ccff; padding: 8px; font-family: monospace;"><strong>' + folder + '/</strong></td>';
                        html += '<td style="border: 1px solid #99ccff; padding: 8px;">' + data.folder_structure[folder] + '</td>';
                        html += '</tr>';
                    }
                    
                    html += '</tbody></table>';
                    html += '<p><em>Note: Folders containing "bug" are highlighted in orange</em></p>';
                    html += '</div>';
                } else {
                    html += '<div style="background: #fff3cd; padding: 15px; margin: 10px 0; border: 1px solid #ffeaa7;">';
                    html += '<h5>📁 No Folder Structure Detected</h5>';
                    html += '<p>All files appear to be in the root directory.</p>';
                    html += '</div>';
                }
                
                // Bug Report Files
                if (data.bug_report_files.length > 0) {
                    html += '<h5>Bug Report Files Found (' + data.bug_report_files.length + ')</h5>';
                    html += '<ul style="background: #d4edda; padding: 15px; border: 1px solid #c3e6cb;">';
                    data.bug_report_files.forEach(function(file) {
                        html += '<li><strong>' + file.key + '</strong> (' + file.size + ' bytes)</li>';
                    });
                    html += '</ul>';
                } else {
                    html += '<div style="background: #f8d7da; padding: 15px; margin: 10px 0; border: 1px solid #f5c6cb;">';
                    html += '<h5>⚠️ No Bug Report Files Found</h5>';
                    html += '<p>This suggests that either:</p>';
                    html += '<ul>';
                    html += '<li>No bug reports exist in the bucket</li>';
                    html += '<li>Bug reports are stored in a different path structure</li>';
                    html += '<li>The detection logic needs adjustment</li>';
                    html += '</ul>';
                    html += '</div>';
                }
                
                // Sample of all files for pattern analysis
                html += '<h5>All Files (First 20 for pattern analysis)</h5>';
                html += '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
                html += '<thead><tr style="background: #f1f1f1;"><th style="border: 1px solid #ddd; padding: 8px;">File Path</th><th style="border: 1px solid #ddd; padding: 8px;">Type</th><th style="border: 1px solid #ddd; padding: 8px;">Size</th></tr></thead>';
                html += '<tbody>';
                
                var allFiles = data.bug_report_files.concat(data.diagnostic_files).concat(data.other_files);
                allFiles.slice(0, 20).forEach(function(file) {
                    var fileType = 'Other';
                    var rowColor = '#ffffff';
                    
                    if (file.is_bug_report && file.is_json) {
                        fileType = 'Bug Report';
                        rowColor = '#d4edda';
                    } else if (file.is_json) {
                        fileType = 'Diagnostic';
                        rowColor = '#cce5ff';
                    }
                    
                    html += '<tr style="background: ' + rowColor + ';">';
                    html += '<td style="border: 1px solid #ddd; padding: 8px; font-family: monospace;">' + file.key + '</td>';
                    html += '<td style="border: 1px solid #ddd; padding: 8px;">' + fileType + '</td>';
                    html += '<td style="border: 1px solid #ddd; padding: 8px;">' + file.size + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
                
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').text('S3 files debug failed: ' + response.data).show();
            }
        }).fail(function() {
            $button.text('Debug S3 Files').prop('disabled', false);
            $result.addClass('error').text('S3 files debug request failed').show();
        });
    });
    
    // Analyze bucket structure
    $(document).on('click', '#analyze-bucket-structure', function() {
        var $button = $(this);
        var $result = $('#bucket-structure-analysis');
        
        $button.text('Analyzing Bucket Structure...').prop('disabled', true);
        $result.hide().removeClass('success error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_analyze_bucket_structure',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Analyze Bucket Structure').prop('disabled', false);
            
            if (response.success) {
                var analysis = response.data;
                var html = '<div class="bucket-structure-analysis"><h4>Comprehensive Bucket Structure Analysis</h4>';
                
                // Summary
                html += '<div style="background: #f0f0f1; padding: 15px; margin: 10px 0; border: 1px solid #ccd0d4;">';
                html += '<h5>📊 Overall Summary</h5>';
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0;">';
                html += '<div style="background: #e7f3ff; padding: 10px; border-radius: 4px; text-align: center;">';
                html += '<strong>' + analysis.total_objects + '</strong><br><small>Total Objects</small>';
                html += '</div>';
                html += '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; text-align: center;">';
                html += '<strong>' + analysis.json_files + '</strong><br><small>JSON Files</small>';
                html += '</div>';
                html += '<div style="background: #fff2e8; padding: 10px; border-radius: 4px; text-align: center;">';
                html += '<strong>' + analysis.bug_report_files + '</strong><br><small>Bug Reports</small>';
                html += '</div>';
                html += '<div style="background: #f0e8ff; padding: 10px; border-radius: 4px; text-align: center;">';
                html += '<strong>' + analysis.diagnostic_files + '</strong><br><small>Diagnostic Files</small>';
                html += '</div>';
                html += '<div style="background: #ffe8e8; padding: 10px; border-radius: 4px; text-align: center;">';
                html += '<strong>' + analysis.directories_found.length + '</strong><br><small>Directories Found</small>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
                
                // Scan Summary
                if (analysis.scan_summary) {
                    html += '<div style="background: #e7f3ff; padding: 15px; margin: 10px 0; border: 1px solid #b3d9ff;">';
                    html += '<h5>🔍 Detailed Scan Summary</h5>';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="background: #cce7ff;"><th style="border: 1px solid #99ccff; padding: 8px;">Search Location</th><th style="border: 1px solid #99ccff; padding: 8px;">Objects Found</th><th style="border: 1px solid #99ccff; padding: 8px;">Status</th></tr></thead>';
                    html += '<tbody>';
                    
                    for (var key in analysis.scan_summary) {
                        var value = analysis.scan_summary[key];
                        var isError = key.includes('_error');
                        var rowStyle = isError ? 'background: #ffebee;' : '';
                        var status = isError ? '❌ Error' : '✅ Success';
                        
                        html += '<tr style="' + rowStyle + '">';
                        html += '<td style="border: 1px solid #99ccff; padding: 8px; font-family: monospace;">' + key.replace('_objects', '').replace('_error', '') + '</td>';
                        html += '<td style="border: 1px solid #99ccff; padding: 8px;">' + (isError ? 'N/A' : value) + '</td>';
                        html += '<td style="border: 1px solid #99ccff; padding: 8px;">' + (isError ? value : status) + '</td>';
                        html += '</tr>';
                    }
                    
                    html += '</tbody></table>';
                    html += '</div>';
                }
                
                // Directories Found
                if (analysis.directories_found.length > 0) {
                    html += '<div style="background: #e8f5e8; padding: 15px; margin: 10px 0; border: 1px solid #c3e6cb;">';
                    html += '<h5>📁 Directories Discovered (' + analysis.directories_found.length + ')</h5>';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="background: #d4edda;"><th style="border: 1px solid #c3e6cb; padding: 8px;">Directory</th><th style="border: 1px solid #c3e6cb; padding: 8px;">File Count</th><th style="border: 1px solid #c3e6cb; padding: 8px;">Latest Timestamp</th><th style="border: 1px solid #c3e6cb; padding: 8px;">Sample Files</th></tr></thead>';
                    html += '<tbody>';
                    
                    analysis.directories_found.forEach(function(dir) {
                        var details = analysis.directory_details[dir];
                        html += '<tr>';
                        html += '<td style="border: 1px solid #c3e6cb; padding: 8px; font-family: monospace;"><strong>' + dir + '</strong></td>';
                        html += '<td style="border: 1px solid #c3e6cb; padding: 8px;">' + details.file_count + '</td>';
                        html += '<td style="border: 1px solid #c3e6cb; padding: 8px;">' + (details.latest_timestamp || 'N/A') + '</td>';
                        html += '<td style="border: 1px solid #c3e6cb; padding: 8px; font-size: 11px;">' + details.sample_files.join('<br>') + '</td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                    html += '</div>';
                } else {
                    html += '<div style="background: #fff3cd; padding: 15px; margin: 10px 0; border: 1px solid #ffeaa7;">';
                    html += '<h5>⚠️ No Directories Found</h5>';
                    html += '<p>This suggests that either:</p>';
                    html += '<ul>';
                    html += '<li>All files are in the root directory</li>';
                    html += '<li>The directory structure is different than expected</li>';
                    html += '<li>There are no diagnostic files in the bucket</li>';
                    html += '</ul>';
                    html += '</div>';
                }
                
                // Potential Issues
                if (analysis.potential_issues.length > 0) {
                    html += '<div style="background: #f8d7da; padding: 15px; margin: 10px 0; border: 1px solid #f5c6cb;">';
                    html += '<h5>⚠️ Potential Issues Detected</h5>';
                    html += '<ul>';
                    analysis.potential_issues.forEach(function(issue) {
                        html += '<li style="color: #721c24;">' + issue + '</li>';
                    });
                    html += '</ul>';
                    html += '</div>';
                }
                
                html += '</div>';
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').text('Bucket structure analysis failed: ' + response.data).show();
            }
        }).fail(function() {
            $button.text('Analyze Bucket Structure').prop('disabled', false);
            $result.addClass('error').text('Bucket structure analysis request failed').show();
        });
    });
    
    // Database Management Actions
    
    // Clear processed files
    $(document).on('click', '#clear-processed-files', function() {
        var $button = $(this);
        var $result = $('#database-action-result');
        
        if (!confirm('Are you sure you want to clear the processed files list? This will allow all files to be re-scanned and re-processed.')) {
            return;
        }
        
        $button.text('Clearing...').prop('disabled', true);
        $result.hide().removeClass('notice-success notice-error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_clear_processed_files',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Clear Processed Files List').prop('disabled', false);
            
            if (response.success) {
                $result.addClass('notice notice-success').html('<p>' + response.data + '</p>').show();
                
                // Refresh database stats after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                $result.addClass('notice notice-error').html('<p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $button.text('Clear Processed Files List').prop('disabled', false);
            $result.addClass('notice notice-error').html('<p>Request failed</p>').show();
        });
    });
    
    // Clear processed directories
    $(document).on('click', '#clear-processed-directories', function() {
        var $button = $(this);
        var $result = $('#database-action-result');
        
        if (!confirm('Are you sure you want to clear the processed directories list? This will allow directories to be re-scanned.')) {
            return;
        }
        
        $button.text('Clearing...').prop('disabled', true);
        $result.hide().removeClass('notice-success notice-error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_clear_processed_directories',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Clear Processed Directories').prop('disabled', false);
            
            if (response.success) {
                $result.addClass('notice notice-success').html('<p>' + response.data + '</p>').show();
                
                // Refresh database stats after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                $result.addClass('notice notice-error').html('<p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $button.text('Clear Processed Directories').prop('disabled', false);
            $result.addClass('notice notice-error').html('<p>Request failed</p>').show();
        });
    });
    
    // Clear all data
    $(document).on('click', '#clear-all-data', function() {
        var $button = $(this);
        var $result = $('#database-action-result');
        
        if (!confirm('Are you sure you want to clear ALL downloaded data? This will remove all bug reports, diagnostic data, and analytics information. This action cannot be undone!')) {
            return;
        }
        
        // Double confirmation for destructive action
        if (!confirm('This will permanently delete all your collected data. Are you absolutely sure?')) {
            return;
        }
        
        $button.text('Clearing All Data...').prop('disabled', true);
        $result.hide().removeClass('notice-success notice-error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_clear_all_data',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Clear All Downloaded Data').prop('disabled', false);
            
            if (response.success) {
                $result.addClass('notice notice-success').html('<p>' + response.data + '</p>').show();
                
                // Refresh page after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                $result.addClass('notice notice-error').html('<p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $button.text('Clear All Downloaded Data').prop('disabled', false);
            $result.addClass('notice notice-error').html('<p>Request failed</p>').show();
        });
    });
    
    // Reset database
    $(document).on('click', '#reset-database', function() {
        var $button = $(this);
        var $result = $('#database-action-result');
        
        if (!confirm('Are you sure you want to RESET the entire database? This will DROP all tables and recreate them from scratch. ALL DATA WILL BE LOST!')) {
            return;
        }
        
        // Triple confirmation for most destructive action
        if (!confirm('This will completely destroy all database tables and data. Are you absolutely certain?')) {
            return;
        }
        
        if (!confirm('Last chance: This action is irreversible and will delete everything. Proceed?')) {
            return;
        }
        
        $button.text('Resetting Database...').prop('disabled', true);
        $result.hide().removeClass('notice-success notice-error');
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_reset_database',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Reset Entire Database').prop('disabled', false);
            
            if (response.success) {
                $result.addClass('notice notice-success').html('<p>' + response.data + '</p>').show();
                
                // Refresh page after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 3000);
            } else {
                $result.addClass('notice notice-error').html('<p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $button.text('Reset Entire Database').prop('disabled', false);
            $result.addClass('notice notice-error').html('<p>Request failed</p>').show();
        });
    });
    
    // Bulk Scanner Functionality
    var bulkScanInterval;
    var bulkScanActive = false;
    
    // Start bulk scan
    $('#start-bulk-scan').on('click', function() {
        var $button = $(this);
        var $result = $('#bulk-scan-results');
        
        $button.text('Initializing...').prop('disabled', true);
        $result.removeClass('success error').html('<div class="scan-progress">Starting bulk scan...</div>').show();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_start_bulk_scan',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                var data = response.data;
                $result.html('<div class="scan-success">Bulk scan prepared! Found ' + data.total_files + ' files to process in ' + data.total_batches + ' batches.</div>');
                
                // Start processing batches
                bulkScanActive = true;
                updateBulkScanUI();
                startBulkScanProcessing();
            } else {
                $result.html('<div class="scan-error">Error: ' + response.data + '</div>');
                $button.text('Start Bulk Scan').prop('disabled', false);
            }
        }).fail(function() {
            $result.html('<div class="scan-error">Failed to start bulk scan</div>');
            $button.text('Start Bulk Scan').prop('disabled', false);
        });
    });
    
    // Resume bulk scan
    $('#resume-bulk-scan').on('click', function() {
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_resume_bulk_scan',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                bulkScanActive = true;
                updateBulkScanUI();
                startBulkScanProcessing();
            }
        });
    });
    
    // Pause bulk scan
    $('#pause-bulk-scan').on('click', function() {
        bulkScanActive = false;
        if (bulkScanInterval) {
            clearInterval(bulkScanInterval);
        }
        updateBulkScanUI();
    });
    
    // Cancel bulk scan
    $('#cancel-bulk-scan').on('click', function() {
        if (confirm('Are you sure you want to cancel the bulk scan?')) {
            $.post(wpstb_ajax.ajax_url, {
                action: 'wpstb_cancel_bulk_scan',
                nonce: wpstb_ajax.nonce
            }, function(response) {
                if (response.success) {
                    bulkScanActive = false;
                    if (bulkScanInterval) {
                        clearInterval(bulkScanInterval);
                    }
                    updateBulkScanUI();
                    updateBulkScanProgress();
                }
            });
        }
    });
    
    // Start bulk scan processing
    function startBulkScanProcessing() {
        // Start processing batches
        processBulkScanBatch();
        
        // Set up progress monitoring
        bulkScanInterval = setInterval(function() {
            if (bulkScanActive) {
                updateBulkScanProgress();
            }
        }, 2000); // Update every 2 seconds
    }
    
    // Process single batch
    function processBulkScanBatch() {
        if (!bulkScanActive) return;
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_process_bulk_batch',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                var progress = response.data;
                
                // Update progress display
                updateBulkScanProgressDisplay(progress);
                
                // Check if completed
                if (progress.status === 'completed') {
                    bulkScanActive = false;
                    clearInterval(bulkScanInterval);
                    updateBulkScanUI();
                    showBulkScanComplete(progress);
                } else if (progress.status === 'processing' && bulkScanActive) {
                    // Continue processing next batch
                    setTimeout(processBulkScanBatch, 1000);
                }
            } else {
                bulkScanActive = false;
                clearInterval(bulkScanInterval);
                updateBulkScanUI();
                $('#bulk-scan-results').html('<div class="scan-error">Batch processing error: ' + response.data + '</div>');
            }
        }).fail(function() {
            bulkScanActive = false;
            clearInterval(bulkScanInterval);
            updateBulkScanUI();
            $('#bulk-scan-results').html('<div class="scan-error">Batch processing failed</div>');
        });
    }
    
    // Update bulk scan progress
    function updateBulkScanProgress() {
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_get_bulk_progress',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            if (response.success) {
                updateBulkScanProgressDisplay(response.data);
            }
        });
    }
    
    // Update progress display
    function updateBulkScanProgressDisplay(progress) {
        if (!progress) return;
        
        $('#bulk-status').text(progress.status.charAt(0).toUpperCase() + progress.status.slice(1));
        $('#bulk-progress').text(progress.processed_files + ' / ' + progress.total_files + ' files (' + progress.percentage + '%)');
        $('#bulk-bug-reports').text(progress.processed_bug_reports || 0);
        $('#bulk-diagnostic-files').text(progress.processed_diagnostic_files || 0);
        $('#bulk-errors').text(progress.error_files || 0);
        $('#bulk-last-update').text(progress.last_update || 'N/A');
        
        // Update progress bar
        $('#bulk-progress-fill').css('width', progress.percentage + '%');
        
        // Update results
        var resultsHtml = '<div class="bulk-scan-progress">';
        resultsHtml += '<p><strong>Current Batch:</strong> ' + progress.current_batch + ' / ' + progress.total_batches + '</p>';
        resultsHtml += '<p><strong>Processing Status:</strong> ' + progress.status + '</p>';
        
        if (progress.errors && progress.errors.length > 0) {
            resultsHtml += '<details style="margin-top: 10px;"><summary>Recent Errors (' + progress.errors.length + ')</summary>';
            resultsHtml += '<div style="max-height: 200px; overflow-y: auto; margin-top: 5px;">';
            progress.errors.slice(-5).forEach(function(error) {
                resultsHtml += '<p style="margin: 2px 0; font-size: 12px; color: #d63638;">' + error.file + ': ' + error.error + '</p>';
            });
            resultsHtml += '</div></details>';
        }
        
        resultsHtml += '</div>';
        $('#bulk-scan-results').html(resultsHtml);
    }
    
    // Update UI based on scan state
    function updateBulkScanUI() {
        var $startBtn = $('#start-bulk-scan');
        var $resumeBtn = $('#resume-bulk-scan');
        var $pauseBtn = $('#pause-bulk-scan');
        var $cancelBtn = $('#cancel-bulk-scan');
        
        if (bulkScanActive) {
            $startBtn.hide();
            $resumeBtn.hide();
            $pauseBtn.show();
            $cancelBtn.show();
        } else {
            $startBtn.show().text('Start Bulk Scan').prop('disabled', false);
            $resumeBtn.show();
            $pauseBtn.hide();
            $cancelBtn.hide();
        }
    }
    
    // Show bulk scan completion message
    function showBulkScanComplete(progress) {
        var message = '<div class="bulk-scan-complete" style="background: #e8f5e8; padding: 15px; border-left: 4px solid #46b450; margin-top: 10px;">';
        message += '<h4 style="color: #46b450; margin: 0 0 10px 0;">✓ Bulk Scan Completed!</h4>';
        message += '<p><strong>Total Files Processed:</strong> ' + progress.processed_files + '</p>';
        message += '<p><strong>Bug Reports:</strong> ' + progress.processed_bug_reports + '</p>';
        message += '<p><strong>Diagnostic Files:</strong> ' + progress.processed_diagnostic_files + '</p>';
        message += '<p><strong>Errors:</strong> ' + progress.error_files + '</p>';
        
        if (progress.start_time && progress.end_time) {
            var duration = Math.round((new Date(progress.end_time) - new Date(progress.start_time)) / 1000);
            message += '<p><strong>Duration:</strong> ' + duration + ' seconds</p>';
        }
        
        message += '<p style="margin-top: 15px; font-style: italic; color: #666;">Page will refresh in 5 seconds to show new data...</p>';
        message += '</div>';
        
        $('#bulk-scan-results').html(message);
        setTimeout(function() { window.location.reload(); }, 5000);
    }
    
    // Initialize bulk scan UI on page load
    $(document).ready(function() {
        updateBulkScanProgress();
        updateBulkScanUI();
    });
    
    // Debug Analyzer Functionality
    $('#debug-get-db-stats').on('click', function() {
        var $button = $(this);
        var $result = $('#debug-db-stats-result');
        
        $button.text('Loading...').prop('disabled', true);
        $result.empty();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_debug_database_stats',
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Get Database Stats').prop('disabled', false);
            
            if (response.success) {
                var stats = response.data;
                var html = '<div class="debug-stats-results">';
                
                // Bug Reports
                html += '<div class="debug-stat-section">';
                html += '<h3>Bug Reports</h3>';
                html += '<p><strong>Total:</strong> ' + stats.bug_reports.total + '</p>';
                html += '<p><strong>Unique Sites:</strong> ' + stats.bug_reports.unique_sites + '</p>';
                if (stats.bug_reports.recent && stats.bug_reports.recent.length > 0) {
                    html += '<details><summary>Recent Bug Reports (' + stats.bug_reports.recent.length + ')</summary>';
                    html += '<div style="max-height: 200px; overflow-y: auto;">';
                    stats.bug_reports.recent.forEach(function(report) {
                        html += '<p style="margin: 5px 0; font-size: 12px;">' + report.site_key + ' - ' + report.site_url + ' (' + report.created_at + ')</p>';
                    });
                    html += '</div></details>';
                }
                html += '</div>';
                
                // Diagnostic Data
                html += '<div class="debug-stat-section">';
                html += '<h3>Diagnostic Data</h3>';
                html += '<p><strong>Total:</strong> ' + stats.diagnostic_data.total + '</p>';
                html += '<p><strong>Unique Sites:</strong> ' + stats.diagnostic_data.unique_sites + '</p>';
                html += '<p><strong>Unique URLs:</strong> ' + stats.diagnostic_data.unique_urls + '</p>';
                
                if (stats.diagnostic_data.sample_urls && stats.diagnostic_data.sample_urls.length > 0) {
                    html += '<details><summary>Sample URLs (' + stats.diagnostic_data.sample_urls.length + ')</summary>';
                    html += '<div style="max-height: 200px; overflow-y: auto;">';
                    stats.diagnostic_data.sample_urls.forEach(function(url) {
                        html += '<p style="margin: 2px 0; font-size: 12px;">' + url.site_url + '</p>';
                    });
                    html += '</div></details>';
                }
                
                if (stats.diagnostic_data.recent && stats.diagnostic_data.recent.length > 0) {
                    html += '<details><summary>Recent Diagnostic Files (' + stats.diagnostic_data.recent.length + ')</summary>';
                    html += '<div style="max-height: 200px; overflow-y: auto;">';
                    stats.diagnostic_data.recent.forEach(function(diag) {
                        html += '<p style="margin: 2px 0; font-size: 12px;">' + diag.file_path + ' - ' + diag.site_url + ' (' + diag.processed_at + ')</p>';
                    });
                    html += '</div></details>';
                }
                html += '</div>';
                
                // Processed Files
                html += '<div class="debug-stat-section">';
                html += '<h3>Processed Files</h3>';
                html += '<p><strong>Total:</strong> ' + stats.processed_files.total + '</p>';
                html += '</div>';
                
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<div class="error">Error: ' + response.data + '</div>');
            }
        });
    });
    
    $('#debug-search-files').on('click', function() {
        var $button = $(this);
        var $result = $('#debug-search-results');
        var searchTerm = $('#debug-search-term').val();
        var fileType = $('#debug-file-type').val();
        
        $button.text('Searching...').prop('disabled', true);
        $result.empty();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_debug_search_file',
            search_term: searchTerm,
            file_type: fileType,
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Search Files').prop('disabled', false);
            
            if (response.success) {
                var data = response.data;
                var html = '<div class="debug-search-results">';
                html += '<h4>Search Results</h4>';
                html += '<p>Found ' + data.total_found + ' files (showing first 50)</p>';
                
                if (data.files && data.files.length > 0) {
                    html += '<table class="wp-list-table widefat fixed striped">';
                    html += '<thead><tr><th>File Key</th><th>Type</th><th>Size</th><th>Last Modified</th><th>Processed</th><th>Actions</th></tr></thead>';
                    html += '<tbody>';
                    
                    data.files.forEach(function(file) {
                        html += '<tr>';
                        html += '<td><code>' + file.key + '</code></td>';
                        html += '<td>' + file.type + '</td>';
                        html += '<td>' + file.size + ' bytes</td>';
                        html += '<td>' + file.last_modified + '</td>';
                        html += '<td>' + (file.is_processed ? 'Yes' : 'No') + '</td>';
                        html += '<td><button class="button button-small debug-analyze-single" data-file-key="' + file.key + '">Analyze</button></td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                } else {
                    html += '<p>No files found matching your search criteria.</p>';
                }
                
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<div class="error">Error: ' + response.data + '</div>');
            }
        });
    });
    
    $(document).on('click', '.debug-analyze-single', function() {
        var fileKey = $(this).data('file-key');
        $('#debug-file-key').val(fileKey);
        $('#debug-analyze-file').trigger('click');
    });
    
    $('#debug-analyze-file').on('click', function() {
        var $button = $(this);
        var $result = $('#debug-analyze-results');
        var fileKey = $('#debug-file-key').val();
        
        if (!fileKey) {
            alert('Please enter a file key');
            return;
        }
        
        $button.text('Analyzing...').prop('disabled', true);
        $result.empty();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_debug_analyze_file',
            file_key: fileKey,
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('Analyze File').prop('disabled', false);
            
            if (response.success) {
                var analysis = response.data;
                var html = '<div class="debug-analysis-results">';
                html += '<h4>File Analysis: ' + analysis.file_info.key + '</h4>';
                
                // File Info
                html += '<div class="debug-section">';
                html += '<h5>File Information</h5>';
                html += '<p><strong>Size:</strong> ' + analysis.file_info.size + ' bytes</p>';
                html += '<p><strong>Type:</strong> ' + analysis.file_type + '</p>';
                html += '<p><strong>Already Processed:</strong> ' + (analysis.file_info.is_processed ? 'Yes' : 'No') + '</p>';
                html += '</div>';
                
                // Structure Analysis
                html += '<div class="debug-section">';
                html += '<h5>Data Structure</h5>';
                html += '<p><strong>Top Level Keys:</strong> ' + analysis.structure_analysis.top_level_keys.join(', ') + '</p>';
                if (analysis.structure_analysis.potential_issues.length > 0) {
                    html += '<p><strong>Potential Issues:</strong></p>';
                    html += '<ul>';
                    analysis.structure_analysis.potential_issues.forEach(function(issue) {
                        html += '<li style="color: #d63638;">' + issue + '</li>';
                    });
                    html += '</ul>';
                }
                html += '</div>';
                
                // Processing Simulation
                html += '<div class="debug-section">';
                html += '<h5>Processing Simulation</h5>';
                html += '<p><strong>Would Insert:</strong> ' + (analysis.processing_simulation.would_insert ? 'Yes' : 'No') + '</p>';
                
                if (analysis.processing_simulation.issues.length > 0) {
                    html += '<p><strong>Issues Found:</strong></p>';
                    html += '<ul>';
                    analysis.processing_simulation.issues.forEach(function(issue) {
                        html += '<li style="color: #d63638;">' + issue + '</li>';
                    });
                    html += '</ul>';
                }
                
                html += '<details><summary>Extracted Data</summary>';
                html += '<pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; white-space: pre-wrap;">' + JSON.stringify(analysis.processing_simulation.extracted_data, null, 2) + '</pre>';
                html += '</details>';
                html += '</div>';
                
                // Database Check
                html += '<div class="debug-section">';
                html += '<h5>Database Check</h5>';
                html += '<p><strong>File Marked as Processed:</strong> ' + (analysis.database_check.file_processed ? 'Yes' : 'No') + '</p>';
                html += '<p><strong>Records Found:</strong> ' + analysis.database_check.records_found.length + '</p>';
                html += '</div>';
                
                // Raw Data
                html += '<div class="debug-section">';
                html += '<details><summary>Raw File Content</summary>';
                html += '<pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; white-space: pre-wrap; max-height: 400px; overflow-y: auto;">' + analysis.raw_content + '</pre>';
                html += '</details>';
                html += '</div>';
                
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<div class="error">Error: ' + response.data + '</div>');
            }
        });
    });
    
    $('#debug-list-files').on('click', function() {
        var $button = $(this);
        var $result = $('#debug-files-list');
        
        $button.text('Loading...').prop('disabled', true);
        $result.empty();
        
        $.post(wpstb_ajax.ajax_url, {
            action: 'wpstb_debug_list_files',
            page: 1,
            nonce: wpstb_ajax.nonce
        }, function(response) {
            $button.text('List Recent Files').prop('disabled', false);
            
            if (response.success) {
                var data = response.data;
                var html = '<div class="debug-files-list">';
                html += '<h4>Recent Files (Page 1 of ' + data.total_pages + ')</h4>';
                html += '<p>Showing ' + data.files.length + ' of ' + data.total_files + ' files</p>';
                
                if (data.files && data.files.length > 0) {
                    html += '<table class="wp-list-table widefat fixed striped">';
                    html += '<thead><tr><th>File Key</th><th>Type</th><th>Size</th><th>Last Modified</th><th>Processed</th><th>Actions</th></tr></thead>';
                    html += '<tbody>';
                    
                    data.files.forEach(function(file) {
                        html += '<tr>';
                        html += '<td><code>' + file.key + '</code></td>';
                        html += '<td>' + file.type + '</td>';
                        html += '<td>' + file.size + ' bytes</td>';
                        html += '<td>' + file.last_modified + '</td>';
                        html += '<td>' + (file.is_processed ? 'Yes' : 'No') + '</td>';
                        html += '<td><button class="button button-small debug-analyze-single" data-file-key="' + file.key + '">Analyze</button></td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                } else {
                    html += '<p>No files found.</p>';
                }
                
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<div class="error">Error: ' + response.data + '</div>');
            }
        });
    });
    
}); 