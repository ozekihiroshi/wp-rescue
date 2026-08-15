ARG PHP_VERSION=8.3

FROM wordpress:php${PHP_VERSION}-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/*
