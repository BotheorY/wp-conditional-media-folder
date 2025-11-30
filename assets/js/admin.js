jQuery(document).ready(function($) {
    var container = $('#wcmf-rules-container');
    
    // Safety check to ensure we are on the right page
    if(container.length === 0) return;

    var template = $('#wcmf-rule-template').html();
    var strings = wcmfData.strings || {};

    // Add new rule
    $('#wcmf-add-rule').on('click', function() {
        var newIndex = container.find('.wcmf-rule-box').length;
        // Robust replace for INDEX placeholder
        var newHtml = template.replace(/INDEX/g, newIndex);
        
        var $newRow = $(newHtml);
        $newRow.find('.rule-number').text(newIndex + 1);
        
        // Ensure inputs are empty (in case of browser autocomplete interference)
        $newRow.find('input').val('');
        
        container.append($newRow);
    });

    // Remove rule with confirmation
    container.on('click', '.wcmf-remove-rule', function(e) {
        e.preventDefault();
        
        if(confirm(strings.confirm || 'Are you sure?')) {
            $(this).closest('.wcmf-rule-box').remove();
            resetIndexes();
        }
    });

    // Re-index array inputs to ensure PHP receives a sequential array (0, 1, 2...)
    function resetIndexes() {
        container.find('.wcmf-rule-box').each(function(i) {
            // Update visual number
            $(this).find('.rule-number').text(i + 1);
            
            // Update input names: wcmf_rules[OLD][field] -> wcmf_rules[NEW][field]
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    // Update the index inside the first set of brackets wcmf_rules[XX] with global flag for robustness
                    var newName = name.replace(/wcmf_rules\[\d+\]/g, 'wcmf_rules[' + i + ']');
                    $(this).attr('name', newName);
                }
			});
            
            $(this).attr('data-index', i);
        });
    }
});