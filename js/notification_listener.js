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
        this.lastSyncTimestamp = null; // Timestamp Firestore du dernier sync
    };

    /**
     * Initialiser Firebase
     */
    NotificationListener.prototype.initFirebase = function() {
        var self = this;
        
        return new Promise(function(resolve, reject) {
            try {
                if (typeof firebase === 'undefined') {
                    console.error('Firebase SDK not loaded');
                    reject('Firebase SDK not loaded');
                    return;
                }

                if (!firebase.apps.length) {
                    firebase.initializeApp(self.firebaseConfig);
                }

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

        // Requête Firestore pour les notifications de l'utilisateur
        var query = self.db.collection('Notifications')
            .where('userId', '==', self.userEmail)
            .orderBy('timestamp', 'desc')
            .limit(100);

        // Écouter les changements en temps réel
        self.unsubscribe = query.onSnapshot(function(snapshot) {
            var newNotifications = [];

            snapshot.docChanges().forEach(function(change) {
                if (change.type === 'added' || change.type === 'modified') {
                    var doc = change.doc;
                    var data = doc.data();
                    
                    // Vérifier si c'est une nouvelle notification (après le dernier sync)
                    if (self.lastSyncTimestamp === null || 
                        (data.timestamp && data.timestamp.toMillis() > self.lastSyncTimestamp.toMillis())) {
                        
                        newNotifications.push({
                            id: doc.id,
                            title: data.title || '',
                            message: data.message || '',
                            type: data.type || 'general',
                            timestamp: data.timestamp,
                            userId: data.userId,
                            bookId: data.bookId || null,
                            bookTitle: data.bookTitle || null,
                            status: data.status || null,
                            reason: data.reason || null,
                            librarianName: data.librarianName || null,
                            updateDate: data.updateDate || null,
                            read: data.read || false
                        });
                        
                        console.log('New notification detected:', data.title);
                    }
                }
            });

            // Si de nouvelles notifications, synchroniser avec Moodle
            if (newNotifications.length > 0) {
                console.log('Syncing ' + newNotifications.length + ' new notification(s)...');
                self.syncWithMoodle(newNotifications);
            }
        }, function(error) {
            console.error('Error listening to Firestore:', error);
        });

        // Synchronisation initiale et périodique (toutes les 30 secondes)
        self.performInitialSync();
        self.syncInterval = setInterval(function() {
            self.performInitialSync();
        }, 30000);
    };

    /**
     * Effectuer une synchronisation initiale
     */
    NotificationListener.prototype.performInitialSync = function() {
        var self = this;
        
        if (!self.db) return;

        // Récupérer les notifications récentes (dernières 72h)
        var oneDayAgo = new Date();
        oneDayAgo.setDate(oneDayAgo.getDate() - 3);
        
        self.db.collection('Notifications')
            .where('userId', '==', self.userEmail)
            .where('timestamp', '>', firebase.firestore.Timestamp.fromDate(oneDayAgo))
            .orderBy('timestamp', 'desc')
            .limit(50)
            .get()
            .then(function(querySnapshot) {
                var notifications = [];
                
                querySnapshot.forEach(function(doc) {
                    var data = doc.data();
                    notifications.push({
                        id: doc.id,
                        title: data.title || '',
                        message: data.message || '',
                        type: data.type || 'general',
                        timestamp: data.timestamp,
                        userId: data.userId,
                        bookId: data.bookId || null,
                        bookTitle: data.bookTitle || null,
                        status: data.status || null,
                        reason: data.reason || null,
                        librarianName: data.librarianName || null,
                        updateDate: data.updateDate || null,
                        read: data.read || false
                    });
                });
                
                if (notifications.length > 0) {
                    console.log('Initial sync: found ' + notifications.length + ' notification(s)');
                    self.syncWithMoodle(notifications);
                }
            })
            .catch(function(error) {
                console.error('Error in initial sync:', error);
            });
    };

    /**
     * Synchroniser avec Moodle via AJAX
     * @param {Array} notifications - Liste des notifications à synchroniser
     */
    NotificationListener.prototype.syncWithMoodle = function(notifications) {
        var self = this;
        
        console.log('Syncing ' + notifications.length + ' notification(s) with Moodle...');

        // Convertir les timestamps Firestore en millisecondes Unix
        var notificationsData = notifications.map(function(notif) {
            return {
                id: notif.id,
                title: notif.title,
                message: notif.message,
                type: notif.type,
                timestamp: notif.timestamp ? notif.timestamp.toDate().toISOString() : null,
                userId: notif.userId,
                bookId: notif.bookId,
                bookTitle: notif.bookTitle,
                status: notif.status,
                reason: notif.reason,
                librarianName: notif.librarianName,
                updateDate: notif.updateDate,
                read: notif.read
            };
        });

        $.ajax({
            url: M.cfg.wwwroot + '/local/biblio_enspy/ajax/sync_notifications.php',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({
                notifications: notificationsData
            }),
            success: function(response) {
                if (response.success) {
                    console.log('✓ Sync successful:', response.results);
                    
                    // Mettre à jour le timestamp du dernier sync
                    if (notifications.length > 0 && notifications[0].timestamp) {
                        self.lastSyncTimestamp = notifications[0].timestamp;
                    }
                    
                
                    // Si de nouvelles notifications ont été créées
                    if (response.results.success > 0) {
                        console.log('✓==== ' + response.results.success + ' notification(s) created in Moodle');
                        
                        // Rafraîchir le badge de notifications
                        self.updateNotificationBadge(response.unread_count);
                        
                        // Afficher un toast
                        self.showToast(response.results.success + ' nouvelle(s) notification(s)', 'success');
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('biblio:newnotifications', [response.results]);
                    } else if (response.results.already_exists > 0) {
                        console.log('ℹ ' + response.results.already_exists + ' notification(s) already exist');
                    }
                } else {
                    console.error('✗ Sync error:', response.error);
                }
            },
            error: function(xhr, status, error) {
                console.error('✗ AJAX error:', error);
                console.error('Response:', xhr.responseText);
            }
        });
    };

/**
 * Mettre à jour le badge de notifications Moodle
 */
NotificationListener.prototype.updateNotificationBadge = function(count) {
    // 1. Mise à jour visuelle du badge (DOM)
    var badge = document.querySelector('[data-region="count-container"]');
    
    if (badge && count > 0) {
        badge.textContent = count;
        badge.style.display = 'block';
    } else if (badge && count <= 0) {
        badge.style.display = 'none';
    }
    
    // 2. Tentative sécurisée de rechargement via l'API Moodle
    try {
        // On vérifie chaque niveau de l'objet pour éviter le "undefined"
        if (window.M && M.core && M.core.notification && typeof M.core.notification.reload === 'function') {
            M.core.notification.reload();
        } else {
            console.log('ℹ Moodle notification reload non disponible, mise à jour DOM uniquement.');
        }
    } catch (e) {
        // On capture l'erreur sans bloquer la suite du script
        console.warn('Notification reload failed safely:', e);
    }
};

    /**
     * Afficher un toast de notification
     */
    NotificationListener.prototype.showToast = function(message, type) {
        type = type || 'success';
        
        var configs = {
            success: {
                icon: '✅',
                gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                shadow: 'rgba(102, 126, 234, 0.4)'
            },
            error: {
                icon: '❌',
                gradient: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                shadow: 'rgba(245, 87, 108, 0.4)'
            }
        };
        
        var config = configs[type] || configs.success;
        var toast = document.createElement('div');
        toast.className = 'biblio-toast';
        
        var icon = document.createElement('span');
        icon.innerHTML = config.icon;
        icon.style.cssText = 'margin-right: 10px; font-size: 20px;';
        
        toast.appendChild(icon);
        toast.appendChild(document.createTextNode(message));
        
        toast.style.cssText = 
            'position: fixed; bottom: 20px; right: 20px;' +
            'background: ' + config.gradient + ';' +
            'color: white; padding: 16px 24px; border-radius: 12px;' +
            'box-shadow: 0 8px 24px ' + config.shadow + '; z-index: 99999;' +
            'font-family: -apple-system, sans-serif; font-size: 15px; font-weight: 500;' +
            'opacity: 0; transform: translateY(100px);' +
            'transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);' +
            'cursor: pointer; display: flex; align-items: center;';
        
        document.body.appendChild(toast);
        
        setTimeout(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        }, 10);
        
        var closeToast = function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(100px)';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 400);
        };
        
        toast.addEventListener('click', closeToast);
        setTimeout(closeToast, 5000);
    };

    /**
     * Arrêter l'écoute
     */
    NotificationListener.prototype.stopListening = function() {
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
        
        if (this.syncInterval) {
            clearInterval(this.syncInterval);
            this.syncInterval = null;
        }
    };

    /**
     * Initialisation globale
     */
    window.BiblioNotificationListener = {
        instance: null,
        
        init: function(firebaseConfig, userEmail) {
            if (this.instance) {
                console.log('NotificationListener already initialized');
                return;
            }
            
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