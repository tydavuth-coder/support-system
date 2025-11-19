import React, { useState, useEffect } from 'react';
import { StyleSheet, Text, View, TextInput, TouchableOpacity, FlatList, ActivityIndicator, Alert, SafeAreaView, StatusBar, ScrollView, Modal } from 'react-native';
import { Ionicons } from '@expo/vector-icons'; 

// *** CONFIGURATION ***
// IP Address របស់អ្នក (172.16.140.131)
const API_URL = 'http://172.16.140.131/support-system/public/api'; 

export default function App() {
  // --- STATES ---
  const [user, setUser] = useState(null);
  const [view, setView] = useState('login'); // 'login', 'list', 'detail', 'create'
  const [loading, setLoading] = useState(false);
  
  // Login Data
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  // Data Lists
  const [tickets, setTickets] = useState([]);
  const [selectedTicket, setSelectedTicket] = useState(null);

  // Create Form Data
  const [newTitle, setNewTitle] = useState('');
  const [newDesc, setNewDesc] = useState('');
  const [newPriority, setNewPriority] = useState('Normal');

  // --- API CALLS ---

  const handleLogin = async () => {
    if (!email || !password) { Alert.alert('Error', 'Required fields missing'); return; }
    setLoading(true);
    try {
      const res = await fetch(`${API_URL}/mobile_login.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const data = await res.json();
      if (data.success) {
        setUser(data.user);
        setView('list');
        fetchTickets(data.user.id);
      } else {
        Alert.alert('Failed', data.message);
      }
    } catch (err) { Alert.alert('Error', 'Network error. Check API URL.'); } 
    finally { setLoading(false); }
  };

  const fetchTickets = async (userId) => {
    setLoading(true);
    try {
      const res = await fetch(`${API_URL}/mobile_tickets.php?user_id=${userId}`);
      const data = await res.json();
      if(data.success) setTickets(data.tickets);
    } catch (err) { console.log(err); } 
    finally { setLoading(false); }
  };

  const fetchTicketDetail = async (ticketId) => {
    setLoading(true);
    try {
        const res = await fetch(`${API_URL}/mobile_ticket_detail.php?id=${ticketId}`);
        const data = await res.json();
        if(data.success) {
            setSelectedTicket(data.ticket);
            setView('detail');
        } else {
            Alert.alert('Error', 'Ticket not found');
        }
    } catch(err) { Alert.alert('Error', 'Cannot load ticket'); }
    finally { setLoading(false); }
  }

  const handleCreateTicket = async () => {
      if(!newTitle) { Alert.alert('Error', 'Title is required'); return; }
      setLoading(true);
      try {
        const res = await fetch(`${API_URL}/mobile_ticket_create.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                user_id: user.id,
                title: newTitle,
                description: newDesc,
                priority: newPriority
            }),
        });
        const data = await res.json();
        if(data.success) {
            Alert.alert('Success', 'Ticket Created!');
            setNewTitle(''); setNewDesc('');
            setView('list');
            fetchTickets(user.id);
        } else {
            Alert.alert('Error', data.message);
        }
      } catch(err) { Alert.alert('Error', 'Network Error'); }
      finally { setLoading(false); }
  }

  const handleLogout = () => {
      setUser(null);
      setEmail('');
      setPassword('');
      setView('login');
  }

  // --- UI HELPERS ---
  const getStatusColor = (s) => {
      switch(s) {
          case 'received': return '#3b82f6';
          case 'in_progress': return '#eab308';
          case 'completed': return '#22c55e';
          case 'rejected': return '#ef4444';
          default: return '#64748b';
      }
  }

  // ================= SCREENS =================

  // 1. LOGIN SCREEN
  if (view === 'login') {
    return (
      <View style={styles.container}>
        <StatusBar barStyle="dark-content" />
        <View style={styles.loginBox}>
          <View style={styles.logoIcon}><Ionicons name="ticket" size={40} color="#2563eb" /></View>
          <Text style={styles.title}>Support System</Text>
          <TextInput style={styles.input} placeholder="Email" value={email} onChangeText={setEmail} autoCapitalize="none" />
          <TextInput style={styles.input} placeholder="Password" value={password} onChangeText={setPassword} secureTextEntry />
          <TouchableOpacity style={styles.btnPrimary} onPress={handleLogin} disabled={loading}>
            {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.btnText}>LOGIN</Text>}
          </TouchableOpacity>
        </View>
      </View>
    );
  }

  // 2. TICKET DETAIL SCREEN
  if (view === 'detail' && selectedTicket) {
      return (
        <SafeAreaView style={styles.safeArea}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => setView('list')}>
                    <Ionicons name="arrow-back" size={24} color="#334155" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>Ticket #{selectedTicket.id}</Text>
                <View style={{width:24}} />
            </View>
            <ScrollView style={styles.content}>
                <View style={styles.detailCard}>
                    <Text style={styles.detailTitle}>{selectedTicket.title}</Text>
                    <View style={styles.metaRow}>
                        <View style={[styles.tag, {backgroundColor: getStatusColor(selectedTicket.status)}]}>
                            <Text style={styles.tagText}>{selectedTicket.status}</Text>
                        </View>
                        <Text style={styles.dateText}>{selectedTicket.created_at}</Text>
                    </View>
                    <View style={styles.divider} />
                    <Text style={styles.label}>Description</Text>
                    <Text style={styles.bodyText}>{selectedTicket.description || 'No description provided.'}</Text>
                    
                    <View style={styles.divider} />
                    <Text style={styles.label}>Details</Text>
                    <View style={styles.infoRow}><Text style={styles.infoLabel}>Type:</Text><Text>{selectedTicket.type}</Text></View>
                    <View style={styles.infoRow}><Text style={styles.infoLabel}>Priority:</Text><Text>{selectedTicket.priority}</Text></View>
                    <View style={styles.infoRow}><Text style={styles.infoLabel}>Assigned To:</Text><Text>{selectedTicket.assignee_name || 'Unassigned'}</Text></View>

                    {selectedTicket.solution && (
                        <View style={styles.solutionBox}>
                            <Text style={styles.solutionTitle}>Solution:</Text>
                            <Text style={{color:'#166534'}}>{selectedTicket.solution}</Text>
                        </View>
                    )}
                </View>
            </ScrollView>
        </SafeAreaView>
      )
  }

  // 3. CREATE TICKET SCREEN
  if (view === 'create') {
      return (
        <SafeAreaView style={styles.safeArea}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => setView('list')}>
                    <Ionicons name="close" size={28} color="#334155" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>New Ticket</Text>
                <TouchableOpacity onPress={handleCreateTicket}>
                    <Text style={{color:'#2563eb', fontWeight:'bold'}}>Save</Text>
                </TouchableOpacity>
            </View>
            <ScrollView style={styles.content}>
                <Text style={styles.label}>Title *</Text>
                <TextInput style={styles.input} value={newTitle} onChangeText={setNewTitle} placeholder="What's the issue?" />
                
                <Text style={styles.label}>Priority</Text>
                <View style={styles.priorityRow}>
                    {['Low', 'Normal', 'High', 'Urgent'].map(p => (
                        <TouchableOpacity key={p} onPress={() => setNewPriority(p)} 
                            style={[styles.priorityBtn, newPriority===p && styles.priorityBtnActive]}>
                            <Text style={[styles.priorityBtnText, newPriority===p && {color:'#fff'}]}>{p}</Text>
                        </TouchableOpacity>
                    ))}
                </View>

                <Text style={styles.label}>Description</Text>
                <TextInput style={[styles.input, {height: 100, textAlignVertical:'top'}]} 
                    value={newDesc} onChangeText={setNewDesc} multiline placeholder="Describe details..." />
            </ScrollView>
        </SafeAreaView>
      )
  }

  // 4. LIST SCREEN (DASHBOARD)
  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar barStyle="dark-content" backgroundColor="#fff" />
      <View style={styles.header}>
        <View>
            <Text style={styles.headerTitle}>My Tickets</Text>
            <Text style={styles.headerUser}>Hello, {user.name}</Text>
        </View>
        <TouchableOpacity onPress={handleLogout}>
            <Ionicons name="log-out-outline" size={24} color="#ef4444" />
        </TouchableOpacity>
      </View>

      <View style={styles.content}>
        {loading && !tickets.length ? <ActivityIndicator size="large" color="#2563eb" /> : (
            <FlatList
            data={tickets}
            keyExtractor={(item) => item.id.toString()}
            contentContainerStyle={{ paddingBottom: 80 }}
            refreshing={loading}
            onRefresh={() => fetchTickets(user.id)}
            ListEmptyComponent={<Text style={styles.emptyText}>No tickets found.</Text>}
            renderItem={({ item }) => (
                <TouchableOpacity style={styles.card} onPress={() => fetchTicketDetail(item.id)}>
                    <View style={styles.cardHeader}>
                        <Text style={styles.ticketId}>#{item.id}</Text>
                        <Text style={{color: getStatusColor(item.status), fontWeight:'bold', fontSize:12, textTransform:'uppercase'}}>{item.status}</Text>
                    </View>
                    <Text style={styles.cardTitle}>{item.title}</Text>
                    <Text style={styles.dateText}>{item.created_at}</Text>
                </TouchableOpacity>
            )}
            />
        )}
      </View>
      
      <TouchableOpacity style={styles.fab} onPress={() => setView('create')}>
        <Ionicons name="add" size={30} color="#fff" />
      </TouchableOpacity>
    </SafeAreaView>
  );
}

// --- STYLES ---
const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f1f5f9', justifyContent: 'center', padding: 20 },
  safeArea: { flex: 1, backgroundColor: '#f8fafc' },
  
  // Login
  loginBox: { backgroundColor: '#fff', padding: 25, borderRadius: 16, elevation: 4 },
  logoIcon: { alignSelf:'center', marginBottom:15, backgroundColor:'#eff6ff', padding:15, borderRadius:50 },
  title: { fontSize: 24, fontWeight: 'bold', color: '#0f172a', marginBottom: 20, textAlign: 'center' },
  input: { backgroundColor: '#fff', borderWidth: 1, borderColor: '#cbd5e1', borderRadius: 8, padding: 12, fontSize: 16, marginBottom: 15 },
  btnPrimary: { backgroundColor: '#2563eb', padding: 14, borderRadius: 8, alignItems: 'center' },
  btnText: { color: '#fff', fontWeight: 'bold', fontSize: 16 },

  // Header
  header: { padding: 16, backgroundColor: '#fff', flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: '#e2e8f0', paddingTop: 40 }, // Adjusted for Expo Go
  headerTitle: { fontSize: 20, fontWeight: 'bold', color: '#0f172a' },
  headerUser: { fontSize: 14, color: '#64748b' },

  // List
  content: { flex: 1, padding: 16 },
  card: { backgroundColor: '#fff', padding: 16, borderRadius: 12, marginBottom: 12, elevation: 2 },
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 8 },
  ticketId: { color: '#64748b', fontWeight: 'bold' },
  cardTitle: { fontSize: 16, fontWeight: '600', color: '#1e293b', marginBottom: 4 },
  dateText: { color: '#94a3b8', fontSize: 12 },
  emptyText: { textAlign: 'center', marginTop: 50, color: '#94a3b8', fontSize: 16 },

  // Detail View
  detailCard: { backgroundColor:'#fff', padding:20, borderRadius:12, elevation:2, marginBottom:20 },
  detailTitle: { fontSize: 22, fontWeight:'bold', color:'#1e293b', marginBottom:10 },
  metaRow: { flexDirection:'row', justifyContent:'space-between', alignItems:'center', marginBottom:15 },
  tag: { paddingHorizontal:10, paddingVertical:4, borderRadius:20 },
  tagText: { color:'#fff', fontSize:12, fontWeight:'bold', textTransform:'uppercase' },
  divider: { height:1, backgroundColor:'#e2e8f0', marginVertical:15 },
  label: { fontSize:14, fontWeight:'bold', color:'#64748b', marginBottom:8 },
  bodyText: { fontSize:16, color:'#334155', lineHeight:24 },
  infoRow: { flexDirection:'row', marginBottom:6 },
  infoLabel: { width:100, color:'#64748b' },
  solutionBox: { marginTop:15, padding:15, backgroundColor:'#dcfce7', borderRadius:8, borderWidth:1, borderColor:'#86efac' },
  solutionTitle: { color:'#15803d', fontWeight:'bold', marginBottom:5 },

  // Create View
  priorityRow: { flexDirection:'row', justifyContent:'space-between', marginBottom:15 },
  priorityBtn: { paddingVertical:8, paddingHorizontal:12, borderRadius:8, borderWidth:1, borderColor:'#cbd5e1' },
  priorityBtnActive: { backgroundColor:'#2563eb', borderColor:'#2563eb' },
  priorityBtnText: { color:'#64748b', fontSize:12 },

  // FAB
  fab: { position: 'absolute', bottom: 20, right: 20, width: 56, height: 56, borderRadius: 28, backgroundColor: '#2563eb', justifyContent: 'center', alignItems: 'center', elevation: 6 },
});