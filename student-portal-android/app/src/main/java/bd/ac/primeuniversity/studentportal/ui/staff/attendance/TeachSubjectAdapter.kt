package bd.ac.primeuniversity.studentportal.ui.staff.attendance

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.TeachSubject
import bd.ac.primeuniversity.studentportal.databinding.ItemTeachSubjectBinding

/** Renders the faculty member's assigned subjects on the picker screen. */
class TeachSubjectAdapter(
    private val onClick: (TeachSubject) -> Unit,
) : RecyclerView.Adapter<TeachSubjectAdapter.Holder>() {

    private val items = mutableListOf<TeachSubject>()

    fun submit(list: List<TeachSubject>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun getItemCount(): Int = items.size

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder =
        Holder(ItemTeachSubjectBinding.inflate(LayoutInflater.from(parent.context), parent, false))

    override fun onBindViewHolder(holder: Holder, position: Int) = holder.bind(items[position])

    inner class Holder(private val binding: ItemTeachSubjectBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(subject: TeachSubject) {
            val context = binding.root.context
            binding.subjectCode.text = subject.courseCode ?: ""
            binding.subjectName.text = subject.courseName ?: ""

            binding.subjectMeta.text = listOfNotNull(
                subject.batchName?.takeIf { it.isNotBlank() },
                subject.semester?.takeIf { it.isNotBlank() },
                subject.academicIntake?.takeIf { it.isNotBlank() },
                subject.section?.takeIf { it.isNotBlank() }?.let { "Sec $it" },
                subject.shift?.takeIf { it.isNotBlank() },
            ).joinToString(" \u00b7 ")

            binding.subjectCounts.text = listOf(
                context.getString(R.string.student_att_students, subject.studentCount),
                context.getString(R.string.student_att_classes, subject.sessionCount),
            ).joinToString(" \u00b7 ")

            binding.root.setOnClickListener { onClick(subject) }
        }
    }
}
