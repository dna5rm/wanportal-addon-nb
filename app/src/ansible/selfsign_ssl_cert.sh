#!/bin/bash
#
# selfsign_ssl_cert.sh - wanportal wrapper for selfsign.yml.
#
# Takes a JSON payload as $1: common_name and owner_group, optional
# subject_alt_names (array). VAULT_PASS, NETBOX_TOKEN and NETBOX_URL pass
# through from the environment. With a JSON HTTP_ACCEPT it captures the
# playbook output, extracts the certificate PEM and prints a JSON object
# (action selfsign, common_name, certificate, success); otherwise a banner and
# the raw playbook output. Exits with the playbook exit code.
#
# Needs jq plus the python packages ansible and fqdn.

export ANSIBLE_PATH="$(dirname "${0}")"
export ANSIBLE_HOME=/tmp/.ansible-$(id -u)
export ANSIBLE_LOCAL_TEMP=/tmp/.ansible-$(id -u)/tmp
export ANSIBLE_REMOTE_TEMP=/tmp/.ansible-$(id -u)/tmp

common_name=$(jq -r '.common_name' <<< "${1}")
owner_group=$(jq -r '.owner_group' <<< "${1}")
alt_names=$(jq -r '.subject_alt_names // [] | @json' <<< "${1}")

EXTRA_VARS="common_name=${common_name} owner_group=${owner_group}"

if echo "${HTTP_ACCEPT}" | grep -qE 'application/json|\*/\*'; then

    output=$(ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/selfsign.yml" \
        -i "${ANSIBLE_PATH}/inventory" \
        -e "${EXTRA_VARS}" \
        -e "{\"alt_names\": ${alt_names}}" | sed 's/^[[:space:]]*//' 2>&1)

    exit_code=$?

    # Extract certificate from output
    cert=$(echo "${output}" | awk '/-----BEGIN CERTIFICATE-----/,/-----END CERTIFICATE-----/')

    jq -n \
        --arg common_name "${common_name}" \
        --arg certificate "${cert}" \
        --argjson success "$([ ${exit_code} -eq 0 ] && echo true || echo false)" \
        '{
            action:      "selfsign",
            common_name: $common_name,
            certificate: $certificate,
            success:     $success
        }'

else

    cat <<EOF
┏━┓┏━╸╻  ┏━╸   ┏━┓┏━┓╻
┗━┓┣╸ ┃  ┣╸ ╺━╸┗━┓┗━┓┃
┗━┛┗━╸┗━╸╹     ┗━┛┗━┛┗━╸

EOF

    ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/selfsign.yml" \
        -i "${ANSIBLE_PATH}/inventory" \
        -e "${EXTRA_VARS}" \
        -e "{\"alt_names\": ${alt_names}}" | sed 's/^[[:space:]]*//'

    exit_code=$?

fi

exit ${exit_code}

