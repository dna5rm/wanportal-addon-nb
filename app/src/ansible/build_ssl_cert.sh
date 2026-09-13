#!/bin/bash
#
# build_ssl_cert.sh - wanportal wrapper for build.yml.
#
# Takes a JSON payload as $1: common_name and owner_group, optional
# reference_number and subject_alt_names (array). VAULT_PASS, NETBOX_TOKEN and
# NETBOX_URL pass through from the environment. With an HTTP_ACCEPT that wants
# JSON it captures the playbook output, extracts the CSR PEM and prints a JSON
# object (action build, common_name, csr, success); otherwise it prints a
# banner and the raw playbook output. Exits with the playbook exit code.
#
# Needs jq plus the python packages ansible, dnspython and fqdn.

export ANSIBLE_PATH="$(dirname "${0}")"
export ANSIBLE_HOME=/tmp/.ansible-$(id -u)
export ANSIBLE_LOCAL_TEMP=/tmp/.ansible-$(id -u)/tmp
export ANSIBLE_REMOTE_TEMP=/tmp/.ansible-$(id -u)/tmp

common_name=$(jq -r '.common_name' <<< "${1}")
owner_group=$(jq -r '.owner_group' <<< "${1}")
reference=$(jq -r '.reference_number // empty' <<< "${1}")
alt_names=$(jq -r '.subject_alt_names // [] | @json' <<< "${1}")

# Build extra vars
EXTRA_VARS="common_name=${common_name} owner_group=${owner_group}"
[[ -n "${reference}" ]] && EXTRA_VARS="${EXTRA_VARS} reference=${reference}"

if echo "${HTTP_ACCEPT}" | grep -qE 'application/json|\*/\*'; then

    # Run the playbook and capture output
    output=$(ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/build.yml" \
        -i "${ANSIBLE_PATH}/inventory" \
        -e "${EXTRA_VARS}" \
        -e "{\"alt_names\": ${alt_names}}" | sed 's/^[[:space:]]*//' 2>&1)

    exit_code=$?

    # Extract CSR from output
    csr=$(echo "${output}" | grep -A1000 'BEGIN CERTIFICATE REQUEST' | grep -B1000 'END CERTIFICATE REQUEST' | sed 's/.*BEGIN CERTIFICATE REQUEST/-----BEGIN CERTIFICATE REQUEST/;s/END CERTIFICATE REQUEST.*/END CERTIFICATE REQUEST-----/')

    jq -n \
        --arg common_name "${common_name}" \
        --arg csr         "${csr}" \
        --argjson success "$([ ${exit_code} -eq 0 ] && echo true || echo false)" \
        '{
            action:      "build",
            common_name: $common_name,
            csr:         $csr,
            success:     $success
        }'

else

    cat <<EOF
┏┓ ╻ ╻╻╻  ╺┳┓   ┏━┓┏━┓╻
┣┻┓┃ ┃┃┃   ┃┃╺━╸┗━┓┗━┓┃
┗━┛┗━┛╹┗━╸╺┻┛   ┗━┛┗━┛┗━╸

EOF

    ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/build.yml" \
        -i "${ANSIBLE_PATH}/inventory" \
        -e "${EXTRA_VARS}" \
        -e "{\"alt_names\": ${alt_names}}" | sed 's/^[[:space:]]*//'

    exit_code=$?

fi

exit ${exit_code}
