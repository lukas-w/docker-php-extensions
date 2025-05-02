IPE_VERSION ?= 2.7.31
IPE_REPO ?= https://github.com/mlocati/docker-php-extension-installer

.PHONY: all
all: ipe/install-php-extensions ipe/supported-extensions ipe/special-requirements

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

.PHONY: clean
clean:
	rm -rf ipe
