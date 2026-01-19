/**
 * Real-time notification listener for Firestore to Moodle sync
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global firebase, M */
    

require(['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {
    'use strict';

    /**
     * Classe pour gérer les notifications en temps réel
     */
    var NotificationListener = function(firebaseConfig, userEmail) {
        this.firebaseConfig = firebaseConfig;
        this.userEmail = userEmail;
        this.db = null;
        this.unsubscribe = null;
        this.syncInterval = null;
        this.lastSyncTime = Date.now();
    };

    /**
     * Initialiser Firebase
     */
    NotificationListener.prototype.initFirebase = function() {
        var self = this;
        
        return new Promise(function(resolve, reject) {
            try {
                // Vérifier si Firebase est déjà initialisé
                if (typeof firebase === 'undefined') {
                    console.error('Firebase SDK not loaded');
                    reject('Firebase SDK not loaded');
                    return;
                }

                // Initialiser Firebase si pas déjà fait
                if (!firebase.apps.length) {
                    firebase.initializeApp(self.firebaseConfig);
                }

                // Obtenir Firestore
                self.db = firebase.firestore();
                
                console.log('Firebase initialized successfully');
                resolve();
                
            } catch (error) {
                console.error('Error initializing Firebase:', error);
                reject(error);
            }
        });
    };

    /**
     * Démarrer l'écoute en temps réel
     */
    NotificationListener.prototype.startListening = function() {
        var self = this;

        if (!self.db) {
            console.error('Firestore not initialized');
            return;
        }

        console.log('Starting real-time listener for user:', self.userEmail);

        // Créer une requête pour les notifications de l'utilisateur
        var query = self.db.collection('Notifications')
            .where('userId', '==', self.userEmail)
            .orderBy('timestamp', 'desc')
            .limit(20);

        // Écouter les changements en temps réel
        self.unsubscribe = query.onSnapshot(function(snapshot) {
            var hasNewNotifications = false;

            snapshot.docChanges().forEach(function(change) {
                if (change.type === 'added' || change.type === 'modified') {
                    var data = change.doc.data();
                    
                    // Vérifier si c'est une nouvelle notification
                    var notifTimestamp = data.timestamp ? data.timestamp.toDate().getTime() : 0;
                    
                    if (notifTimestamp > self.lastSyncTime) {
                        hasNewNotifications = true;
                        console.log('New notification detected:', data.title);
                    }
                }
            });

            // Si de nouvelles notifications, synchroniser avec Moodle
            if (hasNewNotifications) {
                self.syncWithMoodle();
            }
        }, function(error) {
            console.error('Error listening to Firestore:', error);
        });

        // Synchronisation périodique de secours (toutes les 30 secondes)
        self.syncInterval = setInterval(function() {
            self.syncWithMoodle();
        }, 30000);
    };

    /**
     * Synchroniser avec Moodle via AJAX
     */
    NotificationListener.prototype.syncWithMoodle = function() {
        var self = this;
        
        console.log('Syncing notifications with Moodle...');

        $.ajax({
            url: M.cfg.wwwroot + '/local/biblio_enspy/ajax/sync_notifications.php',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    console.log('Sync successful:', response.results);
                    
                    // Mettre à jour le timestamp de dernière sync
                    self.lastSyncTime = response.timestamp * 1000;
                    
                    // Si de nouvelles notifications ont été créées
                    if (response.results.success > 0) {
                        // Rafraîchir le badge de notifications
                        self.updateNotificationBadge(response.unread_count);
                        
                        // Afficher une notification toast
                        self.showToast(response.results.success + ' nouvelle(s) notification(s)');
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('biblio:newnotifications', [response.results]);
                    }
                } else {
                    console.error('Sync error:', response.error);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    };

    /**
     * Mettre à jour le badge de notifications Moodle
     */
    NotificationListener.prototype.updateNotificationBadge = function(count) {
        // Moodle 4.x utilise un badge pour les notifications
        var badge = document.querySelector('[data-region="count-container"]');
        
        if (badge && count > 0) {
            badge.textContent = count;
            badge.style.display = 'block';
        }
        
        // Déclencher le rechargement du popover des notifications
        if (typeof M.core.notification !== 'undefined' && M.core.notification.reload) {
            M.core.notification.reload();
        }
    };

    /**
     * Afficher un toast de notification
     */
    NotificationListener.prototype.showToast = function(message) {
        // Créer un toast simple
        var toast = $('<div>')
            .addClass('biblio-toast')
            .css({
                'position': 'fixed',
                'bottom': '20px',
                'right': '20px',
                'background': '#4CAF50',
                'color': 'white',
                'padding': '15px 20px',
                'border-radius': '4px',
                'box-shadow': '0 2px 5px rgba(0,0,0,0.2)',
                'z-index': 9999,
                'animation': 'slideIn 0.3s ease-out'
            })
            .text(message);
        
        $('body').append(toast);
        
        // Supprimer après 3 secondes
        setTimeout(function() {
            toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    };

    /**
     * Arrêter l'écoute
     */
    NotificationListener.prototype.stopListening = function() {
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
            console.log('Stopped listening to Firestore');
        }
        
        if (this.syncInterval) {
            clearInterval(this.syncInterval);
            this.syncInterval = null;
        }
    };

/**
     * Rendre l'objet disponible globalement APRES la définition de la classe
     */
    window.BiblioNotificationListener = {
        instance: null,
        
        init: function(firebaseConfig, userEmail) {
            if (this.instance) {
                console.log('NotificationListener already initialized');
                return;
            }
            
            // Maintenant NotificationListener est accessible ici car nous sommes dans le même scope
            this.instance = new NotificationListener(firebaseConfig, userEmail);
            
            this.instance.initFirebase()
                .then(function() {
                    window.BiblioNotificationListener.instance.startListening();
                    console.log('NotificationListener started successfully');
                })
                .catch(function(error) {
                    console.error('Failed to start NotificationListener:', error);
                });
        },
        
        stop: function() {
            if (this.instance) {
                this.instance.stopListening();
                this.instance = null;
            }
        }
    };


});

// Ajouter l'animation CSS
var style = document.createElement('style');
style.textContent = `
@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
`;
document.head.appendChild(style);