# WP SpeedTest Browser - Deployment Guide

## Pre-deployment Checklist

### Server Requirements
- [ ] WordPress 5.0 or higher
- [ ] PHP 7.4 or higher
- [ ] MySQL 5.6 or higher
- [ ] Admin access to WordPress
- [ ] S3 bucket access credentials

### S3 Bucket Setup
- [ ] S3 bucket created and accessible
- [ ] Access key and secret key generated
- [ ] Bucket permissions configured for read access
- [ ] Test file uploaded to verify connectivity

## Installation Steps

### 1. Upload Plugin Files
```bash
# Via FTP/SFTP
cd /path/to/wordpress/wp-content/plugins/
mkdir wpspeedtest-browser
# Upload all plugin files to this directory

# Via WordPress Admin
# 1. Zip the plugin directory
# 2. Go to Plugins > Add New > Upload Plugin
# 3. Select the zip file and install
```

### 2. Activate Plugin
```
1. Go to WordPress Admin > Plugins
2. Find "WP SpeedTest Browser"
3. Click "Activate"
```

### 3. Configure Settings
```
1. Go to SpeedTest Browser > Settings
2. Enter S3 credentials:
   - S3 Endpoint: https://your-s3-endpoint.com
   - Access Key: Your access key
   - Secret Key: Your secret key
   - Bucket Name: your-bucket-name
3. Click "Test Connection" to verify
4. Save settings
```

### 4. Initial Data Import
```
1. Go to SpeedTest Browser > Dashboard
2. Click "Scan S3 Bucket"
3. Wait for scan completion
4. Verify data appears in Bug Reports and Analytics
```

## Post-deployment Configuration

### 1. Hosting Providers Setup
```
1. Go to Settings page
2. Click "Update Providers" to download latest data
3. Verify hosting provider data is cached
```

### 2. Scheduled Tasks
The plugin automatically sets up:
- Daily hosting provider updates
- File processing cleanup (30 days)

### 3. User Permissions
Only users with `manage_options` capability can access:
- All plugin pages
- S3 configuration
- Data management functions

## Monitoring and Maintenance

### Daily Checks
- [ ] Monitor scan status on dashboard
- [ ] Check for new bug reports
- [ ] Verify S3 connectivity

### Weekly Tasks
- [ ] Review analytics data for trends
- [ ] Update bug report statuses
- [ ] Export data if needed

### Monthly Tasks
- [ ] Clean up old processed files
- [ ] Review system performance
- [ ] Update hosting providers data

## Troubleshooting

### Common Issues

#### S3 Connection Fails
1. Verify credentials are correct
2. Check endpoint URL format
3. Test bucket permissions
4. Review firewall/security settings

#### No Data Appearing
1. Confirm S3 bucket contains JSON files
2. Run manual scan from dashboard
3. Check WordPress error logs
4. Verify file naming conventions

#### Performance Issues
1. Monitor PHP memory usage
2. Check database query performance
3. Consider scan frequency adjustment
4. Review server resource allocation

### Debug Mode
Enable WordPress debug mode for troubleshooting:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Security Considerations

### Data Protection
- S3 credentials stored in WordPress options (encrypted)
- Admin-only access to sensitive functions
- Nonce verification for all AJAX requests
- Input sanitization and validation

### Access Control
- Capability checks on all admin pages
- Role-based access to plugin features
- Secure file processing and validation

## Backup Recommendations

### Before Deployment
- [ ] Backup WordPress database
- [ ] Backup wp-content directory
- [ ] Document current S3 setup

### Regular Backups
- [ ] Weekly database backups including plugin tables
- [ ] Export analytics data monthly
- [ ] Backup plugin configuration settings

## Rollback Procedure

### If Issues Occur
1. Deactivate plugin via WordPress admin
2. Restore database from backup if needed
3. Remove plugin files if necessary
4. Restore previous backup

### Data Preservation
Plugin tables are preserved during deactivation but can be removed during uninstall if uncommented in uninstall.php.

## Performance Optimization

### Recommended Settings
- PHP memory limit: 256MB or higher
- Max execution time: 300 seconds for large scans
- Database query cache enabled
- Object caching if available

### Scaling Considerations
For large datasets (1000+ sites):
- Consider background processing
- Implement batch processing for scans
- Use database indexing optimization
- Monitor server resource usage

## Support and Maintenance

### Logs and Monitoring
- Check WordPress error logs regularly
- Monitor plugin-specific log entries
- Track scan completion rates
- Review user access patterns

### Updates and Patches
- Test updates in staging environment
- Maintain compatibility with WordPress core
- Document configuration changes
- Backup before major updates 