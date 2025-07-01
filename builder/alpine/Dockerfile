ARG PHP_VERSION
ARG OS_REF
FROM php:${PHP_VERSION}-${OS_REF} AS builder
ARG TARGETPLATFORM
ARG OS_REF

ENV CCACHE_DIR=/tmp/ccache
ENV METADATA_DIR=/usr/local/share/php-exts

RUN --mount=type=cache,id=apk_${OS_REF}_${TARGETPLATFORM},target=/var/cache/apk \
    ln -s /var/cache/apk /etc/apk/cache  \
    && apk -v cache download $PHPIZE_DEPS \
    && apk add --no-cache ccache

COPY --from=ipe install-php-extensions /usr/local/bin/install-php-extensions
RUN chmod +x /usr/local/bin/install-php-extensions

RUN cat /etc/apk/world > apk-before

ARG EXT_NAME
ARG EXT_VERSION
ENV IPE_KEEP_SYSPKG_CACHE=1 \
    IPE_LZF_BETTERCOMPRESSION=1
RUN --mount=type=cache,id=apk_${TARGETPLATFORM},target=/var/cache/apk \
    --mount=type=cache,id=ccache_${TARGETPLATFORM}_${PHP_VERSION}_${EXT_NAME},target=${CCACHE_DIR} \
    if [ ${EXT_VERSION} == "bundled" ]; then \
      install-php-extensions ${EXT_NAME}; \
    else \
      install-php-extensions ${EXT_NAME}-${EXT_VERSION}; \
    fi

RUN cat /etc/apk/world > apk-after
# Find new packages
RUN mkdir -p out${METADATA_DIR} && comm -13 apk-before apk-after > out${METADATA_DIR}/${EXT_NAME}.pkgs

RUN export D=$(php-config --extension-dir) && mkdir -p "out/$D"  && cp -a $D/$EXT_NAME.so out/$D/
RUN export D=$(php-config --ini-dir) && mkdir -p out/$D && cp -a $D/*-$EXT_NAME.ini out/$D/

FROM scratch
ARG EXT_NAME

COPY --link --from=builder out /
#COPY --link php-ext-install-deps /usr/local/bin/
