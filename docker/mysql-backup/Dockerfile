FROM mysql:8.4

COPY --chmod=755 backup.sh schedule-backups.sh /usr/local/bin/

ENTRYPOINT ["/usr/local/bin/schedule-backups.sh"]
