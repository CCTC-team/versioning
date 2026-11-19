//Add any of your own step definitions here
const { Given } = require('@badeball/cypress-cucumber-preprocessor')


/**
 * @module Versioning/MonitoringQR
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should see the field labeled {string} disabled
 * @param {string} field_name - name of field
 * @description Verify field is disabled
 */
Given("I should see the field labeled {string} disabled", (field_name) => {
    let contains = ''
    let last_label = field_name
    field_name.split(' ').forEach((val) => {
        contains += `:has(:contains(${JSON.stringify(val)}))`
        last_label = val
    })
    let outer_element = `tr${contains}:has(input[type=text]):visible:first`

    cy.get(outer_element).within(() => {
        cy.get(`label:contains(${JSON.stringify(last_label)})`)
            .invoke('attr', 'id')
            .then(($id) => {
                let elm = cy.get('[name="' + $id.split('label-')[1] + '"]')
                elm.should('have.attr', 'disabled')
            })
    })
})
