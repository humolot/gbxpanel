<?php

/*
 * One-Click Install catalog (Docker > One-Click Install).
 *
 * Every app is a compose project written to /www/docker/<project>. Fields become entries of the
 * project .env and are referenced as ${KEY} in the compose file. BIND_IP is added by the installer:
 * 127.0.0.1 (reachable through a website reverse proxy) or 0.0.0.0 (external access).
 *
 * Field types: port, password (generated when empty), text, select (options), email.
 */

$port = fn (int $default, string $label = 'Port') => ['label' => $label, 'type' => 'port', 'default' => $default];
$password = fn (string $label = 'Password') => ['label' => $label, 'type' => 'password'];
$version = fn (string $default) => ['label' => 'Version (image tag)', 'type' => 'text', 'default' => $default];

return [
    'categories' => [
        'website' => 'BuildWebsite',
        'database' => 'Database',
        'storage' => 'Storage',
        'ai' => 'AI',
        'tools' => 'Tools',
        'nas' => 'NAS',
        'middleware' => 'Middleware',
        'devops' => 'DevOps',
        'media' => 'Media',
        'email' => 'Email',
        'monitoring' => 'Monitoring',
        'security' => 'Security',
    ],

    'apps' => [

        /* ------------------------------------------------------------ websites */
        'wordpress' => [
            'name' => 'WordPress', 'category' => 'website', 'icon' => 'bi-wordpress', 'color' => '#21759b',
            'description' => 'The most popular CMS, with its own MariaDB database. Point a website reverse proxy to the port to publish it with SSL.',
            'website' => 'https://wordpress.org',
            'fields' => ['APP_PORT' => $port(8090), 'DB_PASSWORD' => $password('Database password'), 'VERSION' => $version('php8.3-apache')],
            'compose' => <<<'YAML'
services:
  wordpress:
    image: wordpress:${VERSION}
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wordpress:/var/www/html
    depends_on:
      - db
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  wordpress:
  db:
YAML,
        ],

        'ghost' => [
            'name' => 'Ghost', 'category' => 'website', 'icon' => 'bi-journal-richtext', 'color' => '#15171a',
            'description' => 'Modern publishing platform for blogs and newsletters, with MySQL 8.',
            'website' => 'https://ghost.org',
            'fields' => ['APP_PORT' => $port(2368), 'SITE_URL' => ['label' => 'Site URL', 'type' => 'text', 'default' => 'http://localhost:2368'], 'DB_PASSWORD' => $password('Database password')],
            'compose' => <<<'YAML'
services:
  ghost:
    image: ghost:5-alpine
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:2368"
    environment:
      url: ${SITE_URL}
      database__client: mysql
      database__connection__host: db
      database__connection__user: ghost
      database__connection__password: ${DB_PASSWORD}
      database__connection__database: ghost
    volumes:
      - content:/var/lib/ghost/content
    depends_on:
      - db
  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ghost
      MYSQL_USER: ghost
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  content:
  db:
YAML,
        ],

        /* ----------------------------------------------------------- databases */
        'mysql' => [
            'name' => 'MySQL', 'category' => 'database', 'icon' => 'bi-database', 'color' => '#00758f',
            'description' => 'MySQL server in a container, independent from the MySQL installed on the host.',
            'website' => 'https://www.mysql.com',
            'fields' => ['APP_PORT' => $port(3307), 'ROOT_PASSWORD' => $password('Root password'), 'VERSION' => $version('8.4')],
            'compose' => <<<'YAML'
services:
  mysql:
    image: mysql:${VERSION}
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3306"
    environment:
      MYSQL_ROOT_PASSWORD: ${ROOT_PASSWORD}
    volumes:
      - data:/var/lib/mysql
volumes:
  data:
YAML,
        ],

        'mariadb' => [
            'name' => 'MariaDB', 'category' => 'database', 'icon' => 'bi-database', 'color' => '#003545',
            'description' => 'Community developed fork of MySQL.',
            'website' => 'https://mariadb.org',
            'fields' => ['APP_PORT' => $port(3308), 'ROOT_PASSWORD' => $password('Root password'), 'VERSION' => $version('11')],
            'compose' => <<<'YAML'
services:
  mariadb:
    image: mariadb:${VERSION}
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3306"
    environment:
      MARIADB_ROOT_PASSWORD: ${ROOT_PASSWORD}
    volumes:
      - data:/var/lib/mysql
volumes:
  data:
YAML,
        ],

        'postgres' => [
            'name' => 'PostgreSQL', 'category' => 'database', 'icon' => 'bi-database-gear', 'color' => '#336791',
            'description' => 'Advanced open source relational database.',
            'website' => 'https://www.postgresql.org',
            'fields' => ['APP_PORT' => $port(5433), 'DB_USER' => ['label' => 'User', 'type' => 'text', 'default' => 'postgres'], 'DB_PASSWORD' => $password(), 'VERSION' => $version('17-alpine')],
            'compose' => <<<'YAML'
services:
  postgres:
    image: postgres:${VERSION}
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:5432"
    environment:
      POSTGRES_USER: ${DB_USER}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - data:/var/lib/postgresql/data
volumes:
  data:
YAML,
        ],

        'mongodb' => [
            'name' => 'MongoDB', 'category' => 'database', 'icon' => 'bi-database', 'color' => '#13aa52',
            'description' => 'Document database with authentication enabled for the root user.',
            'website' => 'https://www.mongodb.com',
            'fields' => ['APP_PORT' => $port(27018), 'ROOT_PASSWORD' => $password('Root password'), 'VERSION' => $version('8')],
            'compose' => <<<'YAML'
services:
  mongodb:
    image: mongo:${VERSION}
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:27017"
    environment:
      MONGO_INITDB_ROOT_USERNAME: root
      MONGO_INITDB_ROOT_PASSWORD: ${ROOT_PASSWORD}
    volumes:
      - data:/data/db
volumes:
  data:
YAML,
        ],

        'redis' => [
            'name' => 'Redis', 'category' => 'database', 'icon' => 'bi-lightning-charge', 'color' => '#d82c20',
            'description' => 'In-memory key-value store with password and append-only persistence.',
            'website' => 'https://redis.io',
            'fields' => ['APP_PORT' => $port(6380), 'REDIS_PASSWORD' => $password(), 'VERSION' => $version('7-alpine')],
            'compose' => <<<'YAML'
services:
  redis:
    image: redis:${VERSION}
    restart: unless-stopped
    command: ["redis-server", "--requirepass", "${REDIS_PASSWORD}", "--appendonly", "yes"]
    ports:
      - "${BIND_IP}:${APP_PORT}:6379"
    volumes:
      - data:/data
volumes:
  data:
YAML,
        ],

        'qdrant' => [
            'name' => 'Qdrant', 'category' => 'database', 'icon' => 'bi-bounding-box-circles', 'color' => '#dc244c',
            'description' => 'Vector database for semantic search and RAG, protected with an API key.',
            'website' => 'https://qdrant.tech',
            'fields' => ['APP_PORT' => $port(6333, 'HTTP port'), 'GRPC_PORT' => $port(6334, 'gRPC port'), 'API_KEY' => $password('API key')],
            'compose' => <<<'YAML'
services:
  qdrant:
    image: qdrant/qdrant:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:6333"
      - "${BIND_IP}:${GRPC_PORT}:6334"
    environment:
      QDRANT__SERVICE__API_KEY: ${API_KEY}
    volumes:
      - storage:/qdrant/storage
volumes:
  storage:
YAML,
        ],

        'pgadmin' => [
            'name' => 'pgAdmin', 'category' => 'database', 'icon' => 'bi-window-sidebar', 'color' => '#2f5f8c',
            'description' => 'Web administration tool for PostgreSQL.',
            'website' => 'https://www.pgadmin.org',
            'fields' => ['APP_PORT' => $port(5050), 'ADMIN_EMAIL' => ['label' => 'Login email', 'type' => 'email', 'default' => 'admin@example.com'], 'ADMIN_PASSWORD' => $password('Login password')],
            'compose' => <<<'YAML'
services:
  pgadmin:
    image: dpage/pgadmin4:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
    environment:
      PGADMIN_DEFAULT_EMAIL: ${ADMIN_EMAIL}
      PGADMIN_DEFAULT_PASSWORD: ${ADMIN_PASSWORD}
    volumes:
      - data:/var/lib/pgadmin
volumes:
  data:
YAML,
        ],

        /* ------------------------------------------------------------- storage */
        'minio' => [
            'name' => 'MinIO', 'category' => 'storage', 'icon' => 'bi-bucket', 'color' => '#c72c48',
            'description' => 'S3 compatible object storage with web console.',
            'website' => 'https://min.io',
            'fields' => ['APP_PORT' => $port(9000, 'S3 API port'), 'CONSOLE_PORT' => $port(9001, 'Console port'), 'ROOT_USER' => ['label' => 'Root user', 'type' => 'text', 'default' => 'admin'], 'ROOT_PASSWORD' => $password('Root password')],
            'compose' => <<<'YAML'
services:
  minio:
    image: minio/minio:latest
    restart: unless-stopped
    command: server /data --console-address ":9001"
    ports:
      - "${BIND_IP}:${APP_PORT}:9000"
      - "${BIND_IP}:${CONSOLE_PORT}:9001"
    environment:
      MINIO_ROOT_USER: ${ROOT_USER}
      MINIO_ROOT_PASSWORD: ${ROOT_PASSWORD}
    volumes:
      - data:/data
volumes:
  data:
YAML,
        ],

        'filebrowser' => [
            'name' => 'File Browser', 'category' => 'storage', 'icon' => 'bi-folder2-open', 'color' => '#40c4ff',
            'description' => 'Web file manager for a data folder inside the project (default login admin, password shown in the container logs).',
            'website' => 'https://filebrowser.org',
            'fields' => ['APP_PORT' => $port(8091)],
            'compose' => <<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
    volumes:
      - ./files:/srv
      - database:/database
      - config:/config
volumes:
  database:
  config:
YAML,
        ],

        /* ------------------------------------------------------------------ AI */
        'ollama' => [
            'name' => 'Ollama', 'category' => 'ai', 'icon' => 'bi-cpu', 'color' => '#000000',
            'description' => 'Run large language models locally (Llama, Qwen, DeepSeek, Mistral) with an OpenAI compatible API.',
            'website' => 'https://ollama.com',
            'fields' => ['APP_PORT' => $port(11434)],
            'compose' => <<<'YAML'
services:
  ollama:
    image: ollama/ollama:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:11434"
    volumes:
      - models:/root/.ollama
volumes:
  models:
YAML,
        ],

        'open-webui' => [
            'name' => 'Open WebUI', 'category' => 'ai', 'icon' => 'bi-chat-square-dots', 'color' => '#111827',
            'description' => 'ChatGPT-like interface for Ollama and OpenAI compatible APIs. The first account created becomes the administrator.',
            'website' => 'https://openwebui.com',
            'fields' => ['APP_PORT' => $port(3010), 'OLLAMA_URL' => ['label' => 'Ollama URL', 'type' => 'text', 'default' => 'http://host.docker.internal:11434']],
            'compose' => <<<'YAML'
services:
  open-webui:
    image: ghcr.io/open-webui/open-webui:main
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8080"
    environment:
      OLLAMA_BASE_URL: ${OLLAMA_URL}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - data:/app/backend/data
volumes:
  data:
YAML,
        ],

        'n8n' => [
            'name' => 'n8n', 'category' => 'ai', 'icon' => 'bi-diagram-3', 'color' => '#ea4b71',
            'description' => 'Workflow automation with AI agents and 400+ integrations.',
            'website' => 'https://n8n.io',
            'fields' => ['APP_PORT' => $port(5678), 'WEBHOOK_URL' => ['label' => 'Public URL', 'type' => 'text', 'default' => 'http://localhost:5678/'], 'TIMEZONE' => ['label' => 'Timezone', 'type' => 'text', 'default' => 'America/Sao_Paulo']],
            'compose' => <<<'YAML'
services:
  n8n:
    image: docker.n8n.io/n8nio/n8n:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:5678"
    environment:
      WEBHOOK_URL: ${WEBHOOK_URL}
      GENERIC_TIMEZONE: ${TIMEZONE}
      TZ: ${TIMEZONE}
      N8N_SECURE_COOKIE: "false"
    volumes:
      - data:/home/node/.n8n
volumes:
  data:
YAML,
        ],

        'flowise' => [
            'name' => 'Flowise', 'category' => 'ai', 'icon' => 'bi-bezier2', 'color' => '#4f46e5',
            'description' => 'Build LLM apps and agents with a drag and drop interface.',
            'website' => 'https://flowiseai.com',
            'fields' => ['APP_PORT' => $port(3011)],
            'compose' => <<<'YAML'
services:
  flowise:
    image: flowiseai/flowise:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
    environment:
      PORT: "3000"
    volumes:
      - data:/root/.flowise
volumes:
  data:
YAML,
        ],

        'anythingllm' => [
            'name' => 'AnythingLLM', 'category' => 'ai', 'icon' => 'bi-robot', 'color' => '#0e0f0f',
            'description' => 'All-in-one AI application: chat with documents, agents and knowledge bases.',
            'website' => 'https://anythingllm.com',
            'fields' => ['APP_PORT' => $port(3012)],
            'compose' => <<<'YAML'
services:
  anythingllm:
    image: mintplexlabs/anythingllm:latest
    restart: unless-stopped
    cap_add:
      - SYS_ADMIN
    ports:
      - "${BIND_IP}:${APP_PORT}:3001"
    environment:
      STORAGE_DIR: /app/server/storage
    volumes:
      - storage:/app/server/storage
volumes:
  storage:
YAML,
        ],

        'libretranslate' => [
            'name' => 'LibreTranslate', 'category' => 'ai', 'icon' => 'bi-translate', 'color' => '#1565c0',
            'description' => 'Self-hosted machine translation API.',
            'website' => 'https://libretranslate.com',
            'fields' => ['APP_PORT' => $port(5000), 'LANGUAGES' => ['label' => 'Languages', 'type' => 'text', 'default' => 'en,pt,es']],
            'compose' => <<<'YAML'
services:
  libretranslate:
    image: libretranslate/libretranslate:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:5000"
    environment:
      LT_LOAD_ONLY: ${LANGUAGES}
    volumes:
      - models:/home/libretranslate/.local
volumes:
  models:
YAML,
        ],

        /* --------------------------------------------------------------- tools */
        'evolution-api' => [
            'name' => 'Evolution API', 'category' => 'tools', 'icon' => 'bi-whatsapp', 'color' => '#25d366',
            'description' => 'WhatsApp API (Baileys and Cloud API) with PostgreSQL and Redis.',
            'website' => 'https://doc.evolution-api.com',
            'fields' => ['APP_PORT' => $port(8080), 'SERVER_URL' => ['label' => 'Server URL', 'type' => 'text', 'default' => 'http://localhost:8080'], 'API_KEY' => $password('Global API key'), 'DB_PASSWORD' => $password('Database password')],
            'compose' => <<<'YAML'
services:
  api:
    image: evoapicloud/evolution-api:latest
    restart: always
    ports:
      - "${BIND_IP}:${APP_PORT}:8080"
    environment:
      SERVER_URL: ${SERVER_URL}
      AUTHENTICATION_API_KEY: ${API_KEY}
      AUTHENTICATION_EXPOSE_IN_FETCH_INSTANCES: "true"
      DATABASE_ENABLED: "true"
      DATABASE_PROVIDER: postgresql
      DATABASE_CONNECTION_URI: postgresql://evolution:${DB_PASSWORD}@postgres:5432/evolution?schema=public
      DATABASE_CONNECTION_CLIENT_NAME: evolution
      CACHE_REDIS_ENABLED: "true"
      CACHE_REDIS_URI: redis://redis:6379/6
      CACHE_REDIS_PREFIX_KEY: evolution
      CACHE_LOCAL_ENABLED: "false"
    volumes:
      - instances:/evolution/instances
    depends_on:
      - postgres
      - redis
  postgres:
    image: postgres:15
    restart: always
    environment:
      POSTGRES_USER: evolution
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: evolution
    volumes:
      - postgres:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: always
    command: ["redis-server", "--appendonly", "yes"]
    volumes:
      - redis:/data
volumes:
  instances:
  postgres:
  redis:
YAML,
        ],

        'gowa' => [
            'name' => 'GOWA (WhatsApp)', 'category' => 'tools', 'icon' => 'bi-chat-dots', 'color' => '#128c7e',
            'description' => 'Go WhatsApp Web multi-device REST API with basic authentication.',
            'website' => 'https://github.com/aldinokemal/go-whatsapp-web-multidevice',
            'fields' => ['APP_PORT' => $port(3000), 'BASIC_USER' => ['label' => 'Basic auth user', 'type' => 'text', 'default' => 'admin'], 'BASIC_PASSWORD' => $password('Basic auth password')],
            'compose' => <<<'YAML'
services:
  gowa:
    image: ghcr.io/aldinokemal/go-whatsapp-web-multidevice:latest
    restart: unless-stopped
    command:
      - rest
      - --basic-auth=${BASIC_USER}:${BASIC_PASSWORD}
      - --port=3000
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
    volumes:
      - storages:/app/storages
volumes:
  storages:
YAML,
        ],

        'tika' => [
            'name' => 'Apache Tika', 'category' => 'tools', 'icon' => 'bi-file-earmark-text', 'color' => '#d22128',
            'description' => 'Extract text and metadata from PDF, Office and 1000+ file types through a REST API.',
            'website' => 'https://tika.apache.org',
            'fields' => ['APP_PORT' => $port(9998)],
            'compose' => <<<'YAML'
services:
  tika:
    image: apache/tika:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:9998"
YAML,
        ],

        'gotenberg' => [
            'name' => 'Gotenberg', 'category' => 'tools', 'icon' => 'bi-filetype-pdf', 'color' => '#6b21a8',
            'description' => 'API to convert HTML, Markdown and Office documents to PDF.',
            'website' => 'https://gotenberg.dev',
            'fields' => ['APP_PORT' => $port(3013)],
            'compose' => <<<'YAML'
services:
  gotenberg:
    image: gotenberg/gotenberg:8
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
YAML,
        ],

        'it-tools' => [
            'name' => 'IT Tools', 'category' => 'tools', 'icon' => 'bi-tools', 'color' => '#18a058',
            'description' => 'Collection of handy online tools for developers (encoders, generators, converters).',
            'website' => 'https://it-tools.tech',
            'fields' => ['APP_PORT' => $port(8092)],
            'compose' => <<<'YAML'
services:
  it-tools:
    image: corentinth/it-tools:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
YAML,
        ],

        'nocodb' => [
            'name' => 'NocoDB', 'category' => 'tools', 'icon' => 'bi-table', 'color' => '#3366ff',
            'description' => 'Open source Airtable alternative: spreadsheets on top of databases.',
            'website' => 'https://nocodb.com',
            'fields' => ['APP_PORT' => $port(8093)],
            'compose' => <<<'YAML'
services:
  nocodb:
    image: nocodb/nocodb:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8080"
    volumes:
      - data:/usr/app/data
volumes:
  data:
YAML,
        ],

        'metabase' => [
            'name' => 'Metabase', 'category' => 'tools', 'icon' => 'bi-bar-chart-line', 'color' => '#509ee3',
            'description' => 'Business intelligence dashboards and questions over your databases.',
            'website' => 'https://www.metabase.com',
            'fields' => ['APP_PORT' => $port(3014)],
            'compose' => <<<'YAML'
services:
  metabase:
    image: metabase/metabase:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
    environment:
      MB_DB_FILE: /metabase-data/metabase.db
    volumes:
      - data:/metabase-data
volumes:
  data:
YAML,
        ],

        'umami' => [
            'name' => 'Umami', 'category' => 'tools', 'icon' => 'bi-graph-up', 'color' => '#111111',
            'description' => 'Privacy-focused web analytics (default login admin / umami, change it after the first access).',
            'website' => 'https://umami.is',
            'fields' => ['APP_PORT' => $port(3015), 'DB_PASSWORD' => $password('Database password'), 'APP_SECRET' => $password('App secret')],
            'compose' => <<<'YAML'
services:
  umami:
    image: ghcr.io/umami-software/umami:postgresql-latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
    environment:
      DATABASE_URL: postgresql://umami:${DB_PASSWORD}@db:5432/umami
      APP_SECRET: ${APP_SECRET}
    depends_on:
      - db
  db:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: umami
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
        ],

        /* ----------------------------------------------------------------- NAS */
        'nextcloud' => [
            'name' => 'Nextcloud', 'category' => 'nas', 'icon' => 'bi-cloud', 'color' => '#0082c9',
            'description' => 'Files, calendar, contacts and collaboration, with MariaDB and Redis.',
            'website' => 'https://nextcloud.com',
            'fields' => ['APP_PORT' => $port(8094), 'ADMIN_USER' => ['label' => 'Admin user', 'type' => 'text', 'default' => 'admin'], 'ADMIN_PASSWORD' => $password('Admin password'), 'DB_PASSWORD' => $password('Database password')],
            'compose' => <<<'YAML'
services:
  app:
    image: nextcloud:apache
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
    environment:
      MYSQL_HOST: db
      MYSQL_DATABASE: nextcloud
      MYSQL_USER: nextcloud
      MYSQL_PASSWORD: ${DB_PASSWORD}
      NEXTCLOUD_ADMIN_USER: ${ADMIN_USER}
      NEXTCLOUD_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      REDIS_HOST: redis
    volumes:
      - nextcloud:/var/www/html
    depends_on:
      - db
      - redis
  db:
    image: mariadb:11
    restart: unless-stopped
    command: --transaction-isolation=READ-COMMITTED
    environment:
      MARIADB_DATABASE: nextcloud
      MARIADB_USER: nextcloud
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
  redis:
    image: redis:7-alpine
    restart: unless-stopped
volumes:
  nextcloud:
  db:
YAML,
        ],

        'syncthing' => [
            'name' => 'Syncthing', 'category' => 'nas', 'icon' => 'bi-arrow-repeat', 'color' => '#0891d1',
            'description' => 'Continuous peer-to-peer file synchronization.',
            'website' => 'https://syncthing.net',
            'fields' => ['APP_PORT' => $port(8384, 'Web UI port'), 'SYNC_PORT' => $port(22000, 'Sync port')],
            'compose' => <<<'YAML'
services:
  syncthing:
    image: syncthing/syncthing:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8384"
      - "${SYNC_PORT}:22000/tcp"
      - "${SYNC_PORT}:22000/udp"
    volumes:
      - data:/var/syncthing
volumes:
  data:
YAML,
        ],

        /* ---------------------------------------------------------- middleware */
        'rabbitmq' => [
            'name' => 'RabbitMQ', 'category' => 'middleware', 'icon' => 'bi-envelope-paper', 'color' => '#ff6600',
            'description' => 'Message broker with the management console.',
            'website' => 'https://www.rabbitmq.com',
            'fields' => ['APP_PORT' => $port(5672, 'AMQP port'), 'CONSOLE_PORT' => $port(15672, 'Console port'), 'RABBIT_USER' => ['label' => 'User', 'type' => 'text', 'default' => 'admin'], 'RABBIT_PASSWORD' => $password()],
            'compose' => <<<'YAML'
services:
  rabbitmq:
    image: rabbitmq:4-management
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:5672"
      - "${BIND_IP}:${CONSOLE_PORT}:15672"
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBIT_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBIT_PASSWORD}
    volumes:
      - data:/var/lib/rabbitmq
volumes:
  data:
YAML,
        ],

        'meilisearch' => [
            'name' => 'Meilisearch', 'category' => 'middleware', 'icon' => 'bi-search', 'color' => '#ff5caa',
            'description' => 'Lightning fast search engine with typo tolerance.',
            'website' => 'https://www.meilisearch.com',
            'fields' => ['APP_PORT' => $port(7700), 'MASTER_KEY' => $password('Master key')],
            'compose' => <<<'YAML'
services:
  meilisearch:
    image: getmeili/meilisearch:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:7700"
    environment:
      MEILI_MASTER_KEY: ${MASTER_KEY}
      MEILI_ENV: production
    volumes:
      - data:/meili_data
volumes:
  data:
YAML,
        ],

        /* -------------------------------------------------------------- devops */
        'gitea' => [
            'name' => 'Gitea', 'category' => 'devops', 'icon' => 'bi-git', 'color' => '#609926',
            'description' => 'Lightweight self-hosted Git service with issues, pull requests and CI.',
            'website' => 'https://about.gitea.com',
            'fields' => ['APP_PORT' => $port(3016, 'Web port'), 'SSH_PORT' => $port(2222, 'SSH port')],
            'compose' => <<<'YAML'
services:
  gitea:
    image: gitea/gitea:1
    restart: unless-stopped
    environment:
      USER_UID: "1000"
      USER_GID: "1000"
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
      - "${SSH_PORT}:22"
    volumes:
      - data:/data
volumes:
  data:
YAML,
        ],

        'portainer' => [
            'name' => 'Portainer', 'category' => 'devops', 'icon' => 'bi-grid-3x3-gap', 'color' => '#13bef9',
            'description' => 'Container management UI (full access to the Docker socket).',
            'website' => 'https://www.portainer.io',
            'fields' => ['APP_PORT' => $port(9443, 'HTTPS port')],
            'compose' => <<<'YAML'
services:
  portainer:
    image: portainer/portainer-ce:lts
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:9443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - data:/data
volumes:
  data:
YAML,
        ],

        'registry' => [
            'name' => 'Docker Registry', 'category' => 'devops', 'icon' => 'bi-archive', 'color' => '#2496ed',
            'description' => 'Private image registry. Keep it behind a reverse proxy with authentication.',
            'website' => 'https://distribution.github.io/distribution/',
            'fields' => ['APP_PORT' => $port(5001)],
            'compose' => <<<'YAML'
services:
  registry:
    image: registry:2
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:5000"
    volumes:
      - data:/var/lib/registry
volumes:
  data:
YAML,
        ],

        'code-server' => [
            'name' => 'code-server', 'category' => 'devops', 'icon' => 'bi-code-slash', 'color' => '#007acc',
            'description' => 'VS Code in the browser, with a password.',
            'website' => 'https://coder.com/docs/code-server',
            'fields' => ['APP_PORT' => $port(8443), 'CODE_PASSWORD' => $password(), 'TIMEZONE' => ['label' => 'Timezone', 'type' => 'text', 'default' => 'America/Sao_Paulo']],
            'compose' => <<<'YAML'
services:
  code-server:
    image: lscr.io/linuxserver/code-server:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8443"
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      PASSWORD: ${CODE_PASSWORD}
    volumes:
      - config:/config
volumes:
  config:
YAML,
        ],

        /* --------------------------------------------------------------- media */
        'jellyfin' => [
            'name' => 'Jellyfin', 'category' => 'media', 'icon' => 'bi-play-btn', 'color' => '#aa5cc3',
            'description' => 'Media server for movies, series and music. Put files in the media folder of the project.',
            'website' => 'https://jellyfin.org',
            'fields' => ['APP_PORT' => $port(8096)],
            'compose' => <<<'YAML'
services:
  jellyfin:
    image: jellyfin/jellyfin:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8096"
    volumes:
      - config:/config
      - cache:/cache
      - ./media:/media
volumes:
  config:
  cache:
YAML,
        ],

        /* --------------------------------------------------------------- email */
        'mailpit' => [
            'name' => 'Mailpit', 'category' => 'email', 'icon' => 'bi-envelope-open', 'color' => '#0f766e',
            'description' => 'SMTP testing server with a web inbox for development.',
            'website' => 'https://mailpit.axllent.org',
            'fields' => ['APP_PORT' => $port(8025, 'Web port'), 'SMTP_PORT' => $port(1025, 'SMTP port')],
            'compose' => <<<'YAML'
services:
  mailpit:
    image: axllent/mailpit:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:8025"
      - "${BIND_IP}:${SMTP_PORT}:1025"
    volumes:
      - data:/data
    environment:
      MP_DATABASE: /data/mailpit.db
volumes:
  data:
YAML,
        ],

        /* ---------------------------------------------------------- monitoring */
        'uptime-kuma' => [
            'name' => 'Uptime Kuma', 'category' => 'monitoring', 'icon' => 'bi-activity', 'color' => '#5cdd8b',
            'description' => 'Uptime monitoring with status pages and notifications.',
            'website' => 'https://uptime.kuma.pet',
            'fields' => ['APP_PORT' => $port(3001)],
            'compose' => <<<'YAML'
services:
  uptime-kuma:
    image: louislam/uptime-kuma:1
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3001"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
        ],

        'grafana' => [
            'name' => 'Grafana', 'category' => 'monitoring', 'icon' => 'bi-speedometer2', 'color' => '#f46800',
            'description' => 'Dashboards and alerting for metrics, logs and traces.',
            'website' => 'https://grafana.com',
            'fields' => ['APP_PORT' => $port(3017), 'ADMIN_PASSWORD' => $password('Admin password')],
            'compose' => <<<'YAML'
services:
  grafana:
    image: grafana/grafana-oss:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:3000"
    environment:
      GF_SECURITY_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
    volumes:
      - data:/var/lib/grafana
volumes:
  data:
YAML,
        ],

        /* ------------------------------------------------------------ security */
        'vaultwarden' => [
            'name' => 'Vaultwarden', 'category' => 'security', 'icon' => 'bi-shield-lock', 'color' => '#175ddc',
            'description' => 'Bitwarden compatible password manager. Browsers require HTTPS: publish it through a website with SSL.',
            'website' => 'https://github.com/dani-garcia/vaultwarden',
            'fields' => ['APP_PORT' => $port(8095), 'DOMAIN' => ['label' => 'Public URL', 'type' => 'text', 'default' => 'https://vault.example.com'], 'ADMIN_TOKEN' => $password('Admin token')],
            'compose' => <<<'YAML'
services:
  vaultwarden:
    image: vaultwarden/server:latest
    restart: unless-stopped
    ports:
      - "${BIND_IP}:${APP_PORT}:80"
    environment:
      DOMAIN: ${DOMAIN}
      ADMIN_TOKEN: ${ADMIN_TOKEN}
      SIGNUPS_ALLOWED: "true"
    volumes:
      - data:/data
volumes:
  data:
YAML,
        ],
    ],
];
