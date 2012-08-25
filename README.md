# PagodaBox MySQL & S3 Backup

A Simple collection of scripts to create MySQL backups on PagodaBox and sync them to S3. Blogged about here: http://www.matthewfordham.com/blog/pagodabox-backup.

## Uses the following scripts:
* http://sourceforge.net/projects/automysqlbackup
* https://github.com/yellowandy/s3sync
* http://aws.amazon.com/sdkforphp/

## Do this:
1. Add your database credentials to `scripts/mysql_dump.sh`
2. Add your S3 credentials to `scripts/s3sdk/config.inc.php`
3. Adjust the Cron jobs in the Boxfile to suit your needs and S3 bucket. 
4. Make sure you have a shared writeable directory in the Boxfile for wherever `scripts/mysql_dump.sh` is set to dump to.