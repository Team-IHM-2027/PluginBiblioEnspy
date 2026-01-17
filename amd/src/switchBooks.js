// À ajouter dans switchbooks.js après la fonction reserveItem

/**
 * Fonction pour annuler une réservation
 */
async function cancelReservation(element) {
    const itemId = element.getAttribute('data-item-id');
    const itemName = element.getAttribute('data-item-name');
    const userDocIdForCancel = userDocId; // Utilise la variable globale

    if (!userDocIdForCancel) {
        await MoodleNotificationHelper.error(
            'Impossible d\'identifier l\'utilisateur. Veuillez vous reconnecter.',
            'Erreur d\'identification'
        );
        return;
    }

    // Confirmation
    const confirmation = await MoodleNotificationHelper.confirm(
        `Êtes-vous sûr de vouloir annuler la réservation de :<br><strong>"${itemName}"</strong>`,
        'Confirmer l\'annulation'
    );

    if (!confirmation.confirmed) {
        return;
    }

    // Sauvegarder l'état original
    const originalText = element.textContent;
    const originalClass = element.className;

    // Mettre à jour l'état du bouton
    element.disabled = true;
    element.textContent = 'Annulation...';
    element.style.cursor = 'wait';

    try {
        const response = await fetch('api_cancel_reservation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                itemId: itemId,
                userDocId: userDocIdForCancel
            })
        });

        const data = await response.json();

        if (data.success) {
            await MoodleNotificationHelper.success(
                'Votre réservation a été annulée avec succès !',
                'Annulation réussie'
            );

            // Retirer l'ID de la liste des réservations
            const index = userReservationIds.indexOf(itemId);
            if (index > -1) {
                userReservationIds.splice(index, 1);
            }

            // Mettre à jour le bouton pour permettre une nouvelle réservation
            element.textContent = 'Réserver';
            element.classList.remove('btn-secondary');
            element.classList.add('btn-primary', 'book-btn-reserve');
            element.disabled = false;
            element.style.cursor = 'pointer';
            element.setAttribute('onclick', 'reserveItem(this)');

            // Supprimer le badge "Réservé par vous"
            const parentElement = element.closest('.book-info');
            if (parentElement) {
                const badge = parentElement.querySelector('.badge-warning');
                if (badge) {
                    badge.remove();
                }
            }

            // Mettre à jour le compteur d'exemplaires
            if (data.newExemplaire !== undefined) {
                const allItems = [...booksData, ...thesesData];
                const itemIndex = allItems.findIndex(i => i.name.split('/').pop() === itemId);
                
                if (itemIndex !== -1) {
                    allItems[itemIndex].exemplaire = data.newExemplaire;
                    
                    const exemplaireSpan = document.querySelector(`[data-id="${itemId}"]`)
                        ?.closest('.book-info')
                        ?.querySelector('.text-success, .text-danger');
                    
                    if (exemplaireSpan) {
                        if (data.newExemplaire > 0) {
                            exemplaireSpan.innerHTML = `<strong>${data.newExemplaire} exemplaire(s)</strong>`;
                            exemplaireSpan.classList.remove('text-danger');
                            exemplaireSpan.classList.add('text-success');
                        }
                    }
                }
            }

        } else {
            await MoodleNotificationHelper.error(
                data.message || 'Une erreur est survenue lors de l\'annulation.',
                'Échec de l\'annulation'
            );

            // Restaurer le bouton
            element.disabled = false;
            element.textContent = originalText;
            element.className = originalClass;
            element.style.cursor = '';
        }

    } catch (error) {
        console.error('Erreur lors de l\'annulation:', error);

        await MoodleNotificationHelper.error(
            'Une erreur réseau est survenue. Veuillez vérifier votre connexion et réessayer.',
            'Erreur réseau'
        );

        // Restaurer le bouton
        element.disabled = false;
        element.textContent = originalText;
        element.className = originalClass;
        element.style.cursor = '';
    }
}