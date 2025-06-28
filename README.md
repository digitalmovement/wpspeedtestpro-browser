# WP SpeedTest Browser

A comprehensive WordPress plugin for managing and analyzing SpeedTest Pro diagnostic data and bug reports from an S3 bucket.

## Features

### Bug Report Management
- View and manage bug reports submitted from SpeedTest Pro plugin
- Filter reports by status (Open, Resolved, etc.)
- Add admin notes to bug reports
- Change bug report status
- Detailed bug report information including site details and environment

### Analytics Dashboard
- Comprehensive analytics of diagnostic data
- WordPress version distribution
- PHP version statistics
- Country-based user distribution
- Hosting provider analysis
- Complete list of sites using SpeedTest Pro
- Interactive charts and graphs

### S3 Integration
- Connect to Cloudflare S3 or any S3-compatible storage
- Automatic scanning and processing of new files
- Duplicate file detection to avoid reprocessing
- Manual and automatic data synchronization

### Hosting Provider Management
- Automatic updates from wpspeedtestpro.com hosting providers database
- Mapping of hosting provider data from diagnostic files
- Cache management for hosting provider information

## Installation

1. Upload the plugin files to the `/wp-content/plugins/wpspeedtest-browser/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure S3 credentials in the Settings page
4. Run initial S3 scan to import existing data

## Configuration

### S3 Settings
Navigate to **SpeedTest Browser > Settings** and configure:

- **S3 Endpoint**: Your S3 bucket endpoint URL
- **Access Key**: S3 access key
- **Secret Key**: S3 secret key  
- **Bucket Name**: Name of your S3 bucket

### Testing Connection
Use the "Test Connection" button to verify your S3 credentials are working correctly.

## Usage

### Dashboard
The main dashboard provides an overview of:
- Total number of sites using SpeedTest Pro
- WordPress and PHP version counts
- Country distribution
- Quick access to scan S3 bucket

### Bug Reports
- View all bug reports or filter by status
- Click "View" to see detailed information about each report
- Update status and add notes for follow-up
- Export data for external analysis

### Analytics
- Visual charts showing distribution of WordPress/PHP versions
- Geographic distribution of users
- Hosting provider statistics
- Complete site listing with environment details

### Data Synchronization
- **Manual Scan**: Use "Scan S3 Bucket" button to process new files
- **Automatic Processing**: Plugin tracks processed files to avoid duplicates
- **Last Scan Time**: Dashboard shows when data was last synchronized

## Data Structure

### Bug Reports
The plugin processes bug report JSON files containing:
- User contact information
- Bug description and priority
- Site environment details
- WordPress/PHP versions
- Active plugins and themes

### Diagnostic Data
Diagnostic files include:
- Server environment information
- WordPress configuration
- Plugin compatibility data
- Performance metrics
- Hosting provider identification

## Database Tables

The plugin creates the following database tables:
- `wp_wpstb_bug_reports` - Bug report data
- `wp_wpstb_diagnostic_data` - Site diagnostic information  
- `wp_wpstb_site_plugins` - Plugin usage tracking
- `wp_wpstb_processed_files` - File processing history
- `wp_wpstb_hosting_providers` - Hosting provider cache

## API Integration

### Hosting Providers
The plugin automatically downloads hosting provider data from:
```
https://assets.wpspeedtestpro.com/wphostingproviders.json
```

This ensures accurate hosting provider identification in analytics.

## Security

- All S3 operations use proper authentication
- Admin-only access to plugin functionality
- Nonce verification for AJAX requests
- Sanitized input handling
- Secure credential storage

## Performance

- Efficient database queries with proper indexing
- File processing deduplication
- Lazy loading of analytics data
- Minimal frontend impact
- Background processing for large datasets

## Troubleshooting

### Connection Issues
- Verify S3 credentials are correct
- Check endpoint URL format
- Ensure bucket permissions allow listing and reading objects

### No Data Showing
- Run manual S3 scan from dashboard
- Check S3 bucket contains JSON files
- Verify file naming convention matches expected format

### Performance Issues
- Large datasets may take time to process
- Consider running scans during off-peak hours
- Monitor WordPress memory limits for large imports

## Support

For support and feature requests, please contact the SpeedTest Pro team.

## Changelog

### Version 1.0.0
- Initial release
- Bug report management system
- Analytics dashboard
- S3 integration
- Hosting provider management
- Responsive admin interface

## License

This plugin is licensed under the GPL v2 or later.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+
- S3-compatible storage access
- Admin-level WordPress access 