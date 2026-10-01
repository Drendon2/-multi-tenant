# Histórico de MariaDB

Las migraciones y los guiones con los que se construyó el esquema cuando el
sistema corría sobre MariaDB. **Ya no se ejecutan**: Laravel solo lee
`database/migrations/`.

Desde el paso a PostgreSQL, el esquema entero está en
`database/sql/postgres/01-esquema.sql`, que reproduce el estado final de todo
esto con los mismos nombres de tablas, columnas, claves e índices.

Se conservan porque sus comentarios explican POR QUÉ existe cada columna,
CHECK e índice. Si una regla del esquema parece arbitraria, la razón suele
estar en la migración que la creó.
