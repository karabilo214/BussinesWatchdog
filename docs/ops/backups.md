# Бэкапы базы и почты

systemd-таймер `bw-backup.timer` (ставит `deploy.sh`), каждый день в 03:15 по Берлину: `pg_dump` в `/opt/business-watchdog/backups/db-<время UTC>.dump`, затем дамп восстанавливается во временный PostgreSQL — без успешного восстановления копия не считается; итог — в `backups/last-success`. Рядом — архив почтовых ящиков `mail-<время UTC>.tar.gz` (логины, псевдонимы и письма; проверяется чтением архива); хранится и уходит во внешнее хранилище по тому же правилу. Восстановить ящики: остановить `mail`, распаковать архив в том `mail-data` (`/var/mail`), запустить `mail`. Хранение (решение владельца): все копии текущей недели; от прошлых недель текущего месяца — последняя копия каждой недели; от прошлых месяцев — последняя копия каждого месяца, за `BW_BACKUP_KEEP_MONTHS` месяцев (12). Кроме того, каждая проверенная копия шифруется ключом `age` и уходит во внешнее хранилище — Contabo Object Storage (`https://eu2.contabostorage.com`, бакет `business-watchdog-backups`; доступ — `BW_BACKUP_S3_*` в `.env` сервера), там хранится по тому же правилу; итог — `backups/last-offsite`. На сервере только **открытый** ключ (`BW_BACKUP_AGE_RECIPIENT`); закрытый — у владельца офлайн (менеджер паролей + запасная копия). Без него внешние копии не расшифровать.

Восстановление внешней копии на любом компьютере:

```sh
rclone copy offsite:business-watchdog-backups/db-<время>.dump.age .     # или скачать в панели Contabo
age -d -i business-watchdog-backup.agekey -o db.dump db-<время>.dump.age
pg_restore --list db.dump                                               # проверить, затем pg_restore в новую базу
```

```sh
ssh bw-prod 'systemctl start bw-backup.service && journalctl -u bw-backup.service -n 5'   # бэкап сейчас
ssh bw-prod 'cat /opt/business-watchdog/backups/last-success; ls -lh /opt/business-watchdog/backups'
scp bw-prod:/opt/business-watchdog/backups/db-<время>.dump .                                # копия себе
# восстановление в работающую базу (остановить приложение, затем):
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose stop app scheduler sync-worker reconciliation-worker web \
  && docker compose exec -T postgres pg_restore -U bw_app -d business_watchdog --clean --if-exists --no-owner < /opt/business-watchdog/backups/db-<время>.dump \
  && docker compose up -d'
```
