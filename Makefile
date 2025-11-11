IPE_VERSION ?= 2.9.13
IPE_REPO ?= https://github.com/mlocati/docker-php-extension-installer

os_supported := alpine3.21 alpine3.22
php_supported := 8.1 8.2 8.3 8.4
targets := $(foreach php,$(php_supported),$(foreach os,$(os_supported),$(php)-$(os)))
data_bundled_targets := $(foreach target,$(targets),data/$(target)/bundled-extensions)

.PHONY: all
all: ipe/install-php-extensions ipe/supported-extensions ipe/special-requirements $(data_bundled_targets)

ipe/install-php-extensions:
	mkdir -p $$(dirname $@)
	curl --output $@ \
		--silent --show-error --fail --location \
		"${IPE_REPO}/releases/download/${IPE_VERSION}/install-php-extensions"
	chmod +x $@

ipe/supported-extensions:
	mkdir -p $$(dirname $@)
	curl --output $@ \
		--silent --show-error --fail --location \
		"${IPE_REPO}/raw/refs/tags/${IPE_VERSION}/data/supported-extensions"

ipe/special-requirements:
	mkdir -p $$(dirname $@)
	curl --output $@ \
		--silent --show-error --fail --location \
		"${IPE_REPO}/raw/refs/tags/${IPE_VERSION}/data/special-requirements"

data/%/bundled-extensions:
	mkdir -p "data/$*"
	docker run --rm \
		php:$* \
		sh -c "docker-php-source extract && \
			find /usr/src/php/ext -mindepth 2 -maxdepth 2 -type f -name 'config.m4' \
			| xargs -n1 dirname | xargs -n1 basename | xargs" \
	> $@

.PHONY: clean
clean:
	rm -rf ipe
